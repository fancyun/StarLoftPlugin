<?php
namespace addon\star_loft_api\logic;

/**
 * 客户中转密钥与调用日志的存取
 *
 * 数据落在站点自己的数据库：安装插件时幂等建表；无 DDL 权限时由管理页给出可手工执行的建表 SQL。
 */
class KeyStore
{
    /** 客户中转密钥表（不含表前缀） */
    const KEY_TABLE = 'star_loft_api_key';

    /** 调用日志表（不含表前缀） */
    const LOG_TABLE = 'star_loft_api_log';

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
     * 幂等建表（安装时与管理页访问时都会调用）
     *
     * @param string|null $error 失败原因
     * @return bool
     */
    public static function ensureTables(&$error = null)
    {
        try {
            \think\Db::execute(self::keyTableSql());
            \think\Db::execute(self::logTableSql());
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
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` DATETIME NULL,
  `update_time` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_access_key` (`access_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='StarLoft API 中转-客户密钥'";
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
  `cost_ms` INT NOT NULL DEFAULT 0,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `create_time` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_access_key` (`access_key`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='StarLoft API 中转-调用日志'";
    }

    // ==================== 密钥 ====================

    /** 全部客户密钥（按 id 倒序） */
    public static function listKeys()
    {
        return \think\Db::name(self::KEY_TABLE)->order('id', 'desc')->select();
    }

    /** 按中转密钥取值 */
    public static function getByAccessKey($accessKey)
    {
        return \think\Db::name(self::KEY_TABLE)->where('access_key', (string)$accessKey)->find();
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
            return \think\Db::name(self::LOG_TABLE)->order('id', 'desc')->limit((int)$limit)->select();
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