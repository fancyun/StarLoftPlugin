<?php
namespace addons\starloft_fv_relay\logic;

/**
 * 客户中转密钥、预付余额与调用日志的存取
 *
 * 数据落在站点自己的数据库：安装插件时幂等建表并补齐新增列；无 DDL 权限时由管理页给出可手工执行的 SQL。
 * 客户的费用走插件自带的预付余额（不复用魔方核心余额表），站点在管理页为客户充值。
 */
class KeyStore
{
    /** 客户中转密钥表（不含表前缀） */
    const KEY_TABLE = 'starloft_fv_relay_key';

    /** 调用日志表（不含表前缀） */
    const LOG_TABLE = 'starloft_fv_relay_log';

    /**
     * 带表前缀的真实表名（ThinkPHP 前缀优先从 Query 取，其次读配置）
     */
    public static function table($name)
    {
        try {
            if (class_exists('think\Db')) {
                $t = \think\Db::name($name)->getTable();
                if (is_string($t) && $t !== '') {
                    return $t;
                }
            }
        } catch (\Throwable $_) {}

        $prefix = '';
        try {
            if (class_exists('think\facade\Config')) {
                $prefix = (string)\think\facade\Config::get('database.connections.mysql.prefix');
            }
        } catch (\Throwable $_) {}
        if ($prefix === '') {
            try {
                $prefix = (string)\think\Db::getConfig('prefix');
            } catch (\Throwable $_) {}
        }
        return $prefix . $name;
    }

    /**
     * 幂等建表并补齐新增列（安装时与管理页访问时都会调用）
     *
     * @param string|null $error 失败原因
     * @return bool
     */
    public static function ensureTables(&$error = null)
    {
        try {
            \think\Db::execute(self::keyTableSql());
            \think\Db::execute(self::logTableSql());
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
        return self::ensureColumns($error);
    }

    /**
     * 为已存在的旧表补齐新增列（建表用 CREATE TABLE IF NOT EXISTS，不会给老表加列）
     */
    public static function ensureColumns(&$error = null)
    {
        $columns = [
            self::KEY_TABLE => [
                'balance' => "ALTER TABLE `%s` ADD COLUMN `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '客户预付余额（元）'",
            ],
            self::LOG_TABLE => [
                'price'         => "ALTER TABLE `%s` ADD COLUMN `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '本次扣费（元）'",
                'balance_after' => "ALTER TABLE `%s` ADD COLUMN `balance_after` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '扣费后余额（元）'",
            ],
        ];
        try {
            foreach ($columns as $table => $cols) {
                $existing = self::columnsOf($table);
                if (empty($existing)) {
                    continue;
                }
                foreach ($cols as $col => $sql) {
                    if (!isset($existing[strtolower($col)])) {
                        \think\Db::execute(sprintf($sql, self::table($table)));
                    }
                }
            }
            return true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    /** 客户密钥表建表 SQL（管理页展示，供无 DDL 权限时手工执行） */
    public static function keyTableSql()
    {
        $table = self::table(self::KEY_TABLE);
        return "CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '客户名称',
  `access_key` VARCHAR(64) NOT NULL COMMENT '中转密钥',
  `access_secret` VARCHAR(128) NOT NULL COMMENT '中转密钥 Secret',
  `permissions` VARCHAR(255) NOT NULL DEFAULT 'all' COMMENT 'all 或逗号分隔权限码',
  `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '客户预付余额（元）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` DATETIME NULL,
  `update_time` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_access_key` (`access_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='StarLoft 人脸中转-客户密钥'";
    }

    /** 调用日志表建表 SQL */
    public static function logTableSql()
    {
        $table = self::table(self::LOG_TABLE);
        return "CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `access_key` VARCHAR(64) NOT NULL DEFAULT '',
  `client_name` VARCHAR(64) NOT NULL DEFAULT '',
  `endpoint` VARCHAR(64) NOT NULL DEFAULT '',
  `method` VARCHAR(8) NOT NULL DEFAULT '',
  `http_status` INT NOT NULL DEFAULT 0,
  `code` INT NOT NULL DEFAULT 0,
  `message` VARCHAR(255) NOT NULL DEFAULT '',
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '本次扣费（元）',
  `balance_after` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '扣费后余额（元）',
  `cost_ms` INT NOT NULL DEFAULT 0,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `create_time` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_access_key` (`access_key`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='StarLoft 人脸中转-调用日志'";
    }

    /** 把多行结果统一成数组（ThinkPHP 的 select() 返回的是集合对象，不是数组） */
    protected static function rows($rows)
    {
        if (is_array($rows)) {
            return $rows;
        }
        if ($rows instanceof \Traversable) {
            return iterator_to_array($rows, false);
        }
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            return (array)$rows->toArray();
        }
        return [];
    }

    /** 把单行结果统一成数组（find() 可能返回集合对象，未命中返回 null） */
    protected static function row($row)
    {
        if ($row === null || $row === false) {
            return null;
        }
        if (is_array($row)) {
            return $row;
        }
        if (is_object($row) && method_exists($row, 'toArray')) {
            return (array)$row->toArray();
        }
        if ($row instanceof \Traversable) {
            return iterator_to_array($row, false);
        }
        return null;
    }

    /** 表已存在的列（名字转小写） */
    protected static function columnsOf($table)
    {
        $cols = [];
        try {
            $raw = self::rows(\think\Db::query('SHOW COLUMNS FROM `' . self::table($table) . '`'));
            foreach ($raw as $row) {
                $name = (string)($row['Field'] ?? ($row['field'] ?? ''));
                if ($name !== '') {
                    $cols[strtolower($name)] = true;
                }
            }
        } catch (\Throwable $_) {}
        return $cols;
    }

    // ==================== 密钥 ====================

    /** 全部客户密钥（按 id 倒序） */
    public static function listKeys()
    {
        return self::rows(\think\Db::name(self::KEY_TABLE)->order('id', 'desc')->select());
    }

    /** 按中转密钥取值 */
    public static function getByAccessKey($accessKey)
    {
        return self::row(\think\Db::name(self::KEY_TABLE)->where('access_key', (string)$accessKey)->find());
    }

    /** 按主键取值 */
    public static function getById($id)
    {
        return self::row(\think\Db::name(self::KEY_TABLE)->where('id', (int)$id)->find());
    }

    /**
     * 新建客户密钥，返回含明文 Secret 的记录（仅此一次可见）
     */
    public static function createKey($clientName, $permissions = 'all', $remark = '')
    {
        $now = date('Y-m-d H:i:s');
        $row = [
            'client_name'   => mb_substr((string)$clientName, 0, 64),
            'access_key'    => self::generateAccessKey(),
            'access_secret' => bin2hex(random_bytes(24)),
            'permissions'   => trim((string)$permissions) !== '' ? trim((string)$permissions) : 'all',
            'balance'       => 0,
            'status'        => 1,
            'remark'        => mb_substr((string)$remark, 0, 255),
            'create_time'   => $now,
            'update_time'   => $now,
        ];
        $row['id'] = \think\Db::name(self::KEY_TABLE)->insertGetId($row);
        return $row;
    }

    /** 重置 Secret，返回新的明文 Secret */
    public static function resetSecret($id)
    {
        $secret = bin2hex(random_bytes(24));
        \think\Db::name(self::KEY_TABLE)->where('id', (int)$id)->update([
            'access_secret' => $secret,
            'update_time'   => date('Y-m-d H:i:s'),
        ]);
        return $secret;
    }

    /** 启停（1 启用 0 停用） */
    public static function setStatus($id, $status)
    {
        return \think\Db::name(self::KEY_TABLE)->where('id', (int)$id)->update([
            'status'      => (int)$status === 1 ? 1 : 0,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 删除密钥 */
    public static function deleteKey($id)
    {
        return \think\Db::name(self::KEY_TABLE)->where('id', (int)$id)->delete();
    }

    /** 生成唯一中转密钥（sk_ + 32 位十六进制） */
    protected static function generateAccessKey()
    {
        for ($i = 0; $i < 5; $i++) {
            $key = 'sk_' . bin2hex(random_bytes(16));
            try {
                if (!self::getByAccessKey($key)) {
                    return $key;
                }
            } catch (\Throwable $_) {
                return $key;
            }
        }
        return 'sk_' . bin2hex(random_bytes(16));
    }

    // ==================== 预付余额 ====================

    /** 读取余额（元） */
    public static function balanceOf($id)
    {
        try {
            $row = self::getById($id);
            return round((float)($row['balance'] ?? 0), 2);
        } catch (\Throwable $_) {
            return 0.0;
        }
    }

    /**
     * 原子预扣：余额充足才扣，返回 [是否成功, 扣后余额]
     *
     * 条件更新（balance >= amount）保证并发下不会扣成负数；
     * $error 非空表示扣费本身执行失败（如表缺 balance 列），调用方应报系统错误而不是「余额不足」。
     */
    public static function charge($id, $amount, &$error = null)
    {
        $amount = round((float)$amount, 2);
        if ($amount <= 0) {
            return [true, self::balanceOf($id)];
        }
        try {
            $affected = \think\Db::name(self::KEY_TABLE)
                ->where('id', (int)$id)
                ->where('status', 1)
                ->where('balance', '>=', $amount)
                ->update([
                    'balance'     => \think\Db::raw('`balance` - ' . $amount),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            if ((int)$affected < 1) {
                return [false, self::balanceOf($id)];
            }
            return [true, self::balanceOf($id)];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return [false, self::balanceOf($id)];
        }
    }

    /** 退费（转发失败时原路退回预扣金额），返回退后余额 */
    public static function refund($id, $amount)
    {
        $amount = round((float)$amount, 2);
        if ($amount <= 0) {
            return self::balanceOf($id);
        }
        try {
            \think\Db::name(self::KEY_TABLE)->where('id', (int)$id)->update([
                'balance'     => \think\Db::raw('`balance` + ' . $amount),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $_) {}
        return self::balanceOf($id);
    }

    /** 充值（正数加、负数减；扣减时余额不足返回 false），返回 [是否成功, 变动后余额] */
    public static function recharge($id, $amount)
    {
        $amount = round((float)$amount, 2);
        if ($amount >= 0) {
            try {
                \think\Db::name(self::KEY_TABLE)->where('id', (int)$id)->update([
                    'balance'     => \think\Db::raw('`balance` + ' . $amount),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
                return [true, self::balanceOf($id)];
            } catch (\Throwable $_) {
                return [false, self::balanceOf($id)];
            }
        }
        // 扣减：不限制状态（停用的客户也要能扣款/退款），条件更新防止扣成负数
        try {
            $affected = \think\Db::name(self::KEY_TABLE)
                ->where('id', (int)$id)
                ->where('balance', '>=', -$amount)
                ->update([
                    'balance'     => \think\Db::raw('`balance` + ' . $amount),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            if ((int)$affected < 1) {
                return [false, self::balanceOf($id)];
            }
            return [true, self::balanceOf($id)];
        } catch (\Throwable $_) {
            return [false, self::balanceOf($id)];
        }
    }

    // ==================== 调用日志 ====================

    /** 写一条调用日志（失败不影响中转） */
    public static function logCall(array $row)
    {
        try {
            \think\Db::name(self::LOG_TABLE)->insert($row);
        } catch (\Throwable $_) {}
    }

    /** 最近调用日志 */
    public static function recentLogs($limit = 50)
    {
        try {
            return self::rows(\think\Db::name(self::LOG_TABLE)->order('id', 'desc')->limit((int)$limit)->select());
        } catch (\Throwable $_) {
            return [];
        }
    }

    /** 某客户当日调用次数（用于日调用上限） */
    public static function countToday($accessKey)
    {
        try {
            return (int)\think\Db::name(self::LOG_TABLE)
                ->where('access_key', (string)$accessKey)
                ->where('create_time', '>=', date('Y-m-d 00:00:00'))
                ->count();
        } catch (\Throwable $_) {
            return 0;
        }
    }
}