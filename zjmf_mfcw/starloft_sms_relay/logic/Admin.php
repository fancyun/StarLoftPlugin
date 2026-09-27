<?php
namespace addons\starloft_sms_relay\logic;

/**
 * 插件管理面板与免登录管理页
 *
 * panel() 返回面板片段，供魔方后台的插件页面（template/admin/index.php）内嵌渲染；
 * render() 输出完整页面，供插件自带的免登录管理地址使用，由配置里的「管理令牌」保护。
 * 两者共用同一套表单处理与密钥/日志区块：面板内的表单回发到当前地址，
 * 免登录页在此基础上额外携带令牌与 nonce。
 */
class Admin
{
    /** 免登录管理页的 nonce 时间窗口（秒）：同一窗口内生成与校验一致，跨窗容忍 ±1 窗 */
    const NONCE_WINDOW = 600;

    /** 管理页单次展示的日志条数 */
    const LOG_LIMIT = 50;

    /**
     * 免登录管理页：校验管理令牌后输出完整页面
     */
    public static function render(array $cfg)
    {
        $token = trim((string)($cfg['admin_token'] ?? ''));
        if ($token === '') {
            http_response_code(403);
            self::page('未配置管理令牌', '<p>请先在插件配置中设置「管理令牌」后再访问本页面，避免密钥管理界面裸奔。</p>');
            return;
        }
        $given = (string)($_GET['token'] ?? $_POST['token'] ?? '');
        if ($given === '' || !hash_equals($token, $given)) {
            http_response_code(403);
            self::page('403 无权访问', '<p>请在 URL 上带正确的 <code>?token=</code> 参数。</p>');
            return;
        }
        self::page('StarLoft 短信中转 · 管理', self::panel($cfg, $token));
    }

    /**
     * 面板片段（不含 HTML 骨架）：魔方后台插件页面与免登录管理页共用
     *
     * @param array       $cfg   插件配置；为空时不显示单价与平台连通测试（后台嵌入页读不到插件配置）
     * @param string|null $token 管理令牌；传 null 表示管理员登录已由魔方校验，表单不带令牌与 nonce
     */
    public static function panel(array $cfg = [], $token = null)
    {
        $notice = self::handlePost($cfg, $token);

        $tableError = null;
        $tablesOk   = KeyStore::ensureTables($tableError);
        $keys = [];
        $logs = [];
        if ($tablesOk) {
            try {
                $keys = KeyStore::listKeys();
                $logs = KeyStore::recentLogs(self::LOG_LIMIT);
            } catch (\Throwable $e) {
                $tablesOk   = false;
                $tableError = $e->getMessage();
            }
        }

        $postUrl = self::selfUrl();
        $hidden  = '';
        if ($token !== null) {
            $postUrl .= '?token=' . rawurlencode((string)$token);
            $hidden   = '<input type="hidden" name="token" value="' . self::e((string)$token) . '">'
                . '<input type="hidden" name="nonce" value="' . self::e(self::nonce((string)$token)) . '">';
        }

        $html = '';
        if ($notice !== '') {
            $html .= '<div class="notice">' . self::e($notice) . '</div>';
        }
        if (!empty($cfg)) {
            $html .= '<div class="card"><b>当前计费单价</b>（0 表示免费；短信按号码个数计费，长短信拆分条数需自行覆盖）<br>'
                . '短信发送 ' . Relay::money($cfg['price_sms_send'] ?? 0) . ' 元/号码，'
                . '其余端点 ' . Relay::money($cfg['price_other'] ?? 0) . ' 元/次。'
                . '调用成功才扣费，平台返回失败会自动退还。</div>';
            $html .= '<form class="inline" method="post" action="' . self::e($postUrl) . '">' . $hidden
                . '<input type="hidden" name="action" value="test">'
                . '<button type="submit">测试平台连通/鉴权</button></form>';
        }
        if (!$tablesOk) {
            $html .= '<div class="warn"><b>数据表不可用：</b>' . self::e((string)$tableError)
                . '<p>中转仍可工作，但密钥管理不可用。请让 DBA 执行下面的 SQL 后刷新本页：</p>'
                . '<pre>' . self::e(KeyStore::keyTableSql()) . "\n\n" . self::e(KeyStore::logTableSql()) . '</pre></div>';
        }
        $html .= self::keySection($postUrl, $hidden, $keys);
        $html .= self::logSection($logs);
        return self::style() . '<div class="sl-relay">' . $html . '</div>';
    }

    /**
     * 处理面板上的 POST 动作，返回提示文案（非 POST 返回空串）
     *
     * 免登录管理页额外校验 nonce；后台嵌入页的管理员登录由魔方保证，不再校验。
     */
    protected static function handlePost(array $cfg, $token)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return '';
        }
        if ($token !== null && !self::checkNonce((string)$token, (string)($_POST['nonce'] ?? ''))) {
            return '操作被拒绝：表单已过期，请刷新页面重试。';
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'test') {
            list($ok, $msg) = Relay::testPlatform($cfg);
            return ($ok ? '✅ ' : '❌ ') . $msg;
        }
        return self::handleAction($action);
    }

    /**
     * 执行管理动作，返回提示文案
     */
    protected static function handleAction($action)
    {
        $id = (int)($_POST['id'] ?? 0);
        try {
            switch ($action) {
                case 'create':
                    $row = KeyStore::createKey(
                        (string)($_POST['client_name'] ?? ''),
                        (string)($_POST['permissions'] ?? 'all'),
                        (string)($_POST['remark'] ?? '')
                    );
                    return '已创建客户密钥：' . $row['access_key'] . '（Secret 仅本次显示，请立即复制：' . $row['access_secret'] . '）';
                case 'disable':
                    KeyStore::setStatus($id, 0);
                    return '已停用密钥 #' . $id;
                case 'enable':
                    KeyStore::setStatus($id, 1);
                    return '已启用密钥 #' . $id;
                case 'reset':
                    return '已重置密钥 #' . $id . ' 的 Secret（新 Secret 仅本次显示：' . KeyStore::resetSecret($id) . '）';
                case 'recharge':
                case 'deduct':
                    $amount = round((float)($_POST['amount'] ?? 0), 2);
                    if ($amount <= 0) {
                        return '请输入大于 0 的金额';
                    }
                    list($ok, $balance) = KeyStore::recharge($id, $action === 'recharge' ? $amount : -$amount);
                    if (!$ok) {
                        return '操作失败：余额不足（当前 ' . Relay::money($balance) . ' 元）';
                    }
                    return '已' . ($action === 'recharge' ? '充值' : '扣减') . ' ' . Relay::money($amount)
                        . ' 元，客户 #' . $id . ' 当前余额 ' . Relay::money($balance) . ' 元';
                case 'delete':
                    KeyStore::deleteKey($id);
                    return '已删除密钥 #' . $id;
            }
        } catch (\Throwable $e) {
            return '操作失败：' . $e->getMessage();
        }
        return '未识别的操作';
    }

    /** 客户密钥区块（新建表单 + 列表） */
    protected static function keySection($postUrl, $hidden, array $keys)
    {
        $h = '<h2>客户密钥</h2>';
        $h .= '<form class="card" method="post" action="' . self::e($postUrl) . '">' . $hidden
            . '<input type="hidden" name="action" value="create">'
            . '<b>新建客户密钥</b><div class="row">'
            . '<input name="client_name" placeholder="客户名称" required maxlength="64">'
            . '<input name="permissions" placeholder="权限：all 或 sms_send,sms_report" value="all">'
            . '<input name="remark" placeholder="备注（可选）" maxlength="255">'
            . '<button type="submit">生成密钥</button></div></form>';

        $h .= '<table><thead><tr>'
            . '<th>ID</th><th>客户</th><th>中转密钥</th><th>余额(元)</th><th>权限</th><th>状态</th><th>备注</th><th>创建时间</th><th>操作</th>'
            . '</tr></thead><tbody>';
        if (empty($keys)) {
            $h .= '<tr><td colspan="9" class="empty">暂无客户密钥</td></tr>';
        }
        foreach ((array)$keys as $row) {
            $id = (int)($row['id'] ?? 0);
            $h .= '<tr>'
                . '<td>' . $id . '</td>'
                . '<td>' . self::e((string)($row['client_name'] ?? '')) . '</td>'
                . '<td><code>' . self::e(self::maskKey((string)($row['access_key'] ?? ''))) . '</code></td>'
                . '<td>' . Relay::money($row['balance'] ?? 0) . '</td>'
                . '<td>' . self::e((string)($row['permissions'] ?? '')) . '</td>'
                . '<td>' . ((int)($row['status'] ?? 0) === 1 ? '启用' : '<span class="off">停用</span>') . '</td>'
                . '<td>' . self::e((string)($row['remark'] ?? '')) . '</td>'
                . '<td>' . self::e((string)($row['create_time'] ?? '')) . '</td>'
                . '<td>' . self::actionForms($postUrl, $hidden, $id, (int)($row['status'] ?? 0) === 1)
                . self::balanceForm($postUrl, $hidden, $id) . '</td>'
                . '</tr>';
        }
        $h .= '</tbody></table>';
        return $h;
    }

    /** 单行操作按钮（各自独立表单） */
    protected static function actionForms($postUrl, $hidden, $id, $enabled)
    {
        $base = $hidden . '<input type="hidden" name="id" value="' . (int)$id . '">';
        $btn  = function ($action, $label, $confirm = '') use ($postUrl, $base) {
            return '<form class="inline" method="post" action="' . self::e($postUrl) . '"'
                . ($confirm !== '' ? ' onsubmit="return confirm(\'' . self::e($confirm) . '\');"' : '') . '>'
                . $base . '<input type="hidden" name="action" value="' . self::e($action) . '">'
                . '<button type="submit">' . self::e($label) . '</button></form>';
        };
        $out  = $btn($enabled ? 'disable' : 'enable', $enabled ? '停用' : '启用');
        $out .= $btn('reset', '重置密钥', '重置后旧 Secret 立即失效，确认？');
        $out .= $btn('delete', '删除', '删除后客户将无法调用，确认？');
        return $out;
    }

    /** 单客户的余额操作（充值/扣减），金额为空或非正数时不提交 */
    protected static function balanceForm($postUrl, $hidden, $id)
    {
        return '<form class="inline" method="post" action="' . self::e($postUrl) . '"'
            . ' onsubmit="if(!(parseFloat(this.amount.value)>0)){alert(\'请输入大于 0 的金额\');return false;}">'
            . $hidden
            . '<input type="hidden" name="id" value="' . (int)$id . '">'
            . '<input name="amount" type="number" step="0.01" min="0.01" placeholder="金额" style="width:78px;padding:4px;">'
            . '<button type="submit" name="action" value="recharge">充值</button>'
            . '<button type="submit" name="action" value="deduct">扣减</button>'
            . '</form>';
    }

    /** 最近调用日志区块 */
    protected static function logSection(array $logs)
    {
        $h = '<h2>最近调用日志（' . self::LOG_LIMIT . ' 条）</h2>';
        $h .= '<table><thead><tr>'
            . '<th>时间</th><th>客户</th><th>端点</th><th>方法</th><th>HTTP</th><th>业务码</th><th>扣费(元)</th><th>余额(元)</th><th>耗时(ms)</th><th>IP</th><th>消息</th>'
            . '</tr></thead><tbody>';
        if (empty($logs)) {
            $h .= '<tr><td colspan="11" class="empty">暂无日志</td></tr>';
        }
        foreach ((array)$logs as $row) {
            $h .= '<tr>'
                . '<td>' . self::e((string)($row['create_time'] ?? '')) . '</td>'
                . '<td>' . self::e((string)($row['client_name'] ?? '')) . '</td>'
                . '<td>' . self::e((string)($row['endpoint'] ?? '')) . '</td>'
                . '<td>' . self::e((string)($row['method'] ?? '')) . '</td>'
                . '<td>' . (int)($row['http_status'] ?? 0) . '</td>'
                . '<td>' . (int)($row['code'] ?? 0) . '</td>'
                . '<td>' . Relay::money($row['price'] ?? 0) . '</td>'
                . '<td>' . Relay::money($row['balance_after'] ?? 0) . '</td>'
                . '<td>' . (int)($row['cost_ms'] ?? 0) . '</td>'
                . '<td>' . self::e((string)($row['ip'] ?? '')) . '</td>'
                . '<td>' . self::e((string)($row['message'] ?? '')) . '</td>'
                . '</tr>';
        }
        $h .= '</tbody></table>';
        return $h;
    }

    /** 面板样式（片段自带，避免依赖魔方后台样式） */
    protected static function style()
    {
        return <<<HTML
<style>
.sl-relay{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;color:#1f2937;font-size:13px;}
.sl-relay h2{font-size:16px;margin:28px 0 10px;}
.sl-relay table{width:100%;border-collapse:collapse;background:#fff;font-size:13px;}
.sl-relay th,.sl-relay td{border:1px solid #e5e7eb;padding:8px;text-align:left;vertical-align:top;}
.sl-relay th{background:#f9fafb;font-weight:600;}
.sl-relay code{background:#f3f4f6;padding:1px 4px;border-radius:3px;}
.sl-relay .notice{background:#ecfdf5;border:1px solid #10b981;color:#065f46;padding:10px 12px;border-radius:6px;margin-bottom:14px;word-break:break-all;}
.sl-relay .warn{background:#fffbeb;border:1px solid #f59e0b;color:#92400e;padding:10px 12px;border-radius:6px;margin-bottom:14px;}
.sl-relay .warn pre{background:#fff;padding:10px;overflow:auto;font-size:12px;}
.sl-relay .card{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:14px;}
.sl-relay .row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}
.sl-relay .row input{flex:1;min-width:180px;padding:7px 9px;border:1px solid #d1d5db;border-radius:4px;}
.sl-relay button{padding:6px 12px;border:1px solid #1cd5c7;background:#1cd5c7;color:#fff;border-radius:4px;cursor:pointer;font-size:12px;}
.sl-relay button:hover{opacity:.9;}
.sl-relay .inline{display:inline-block;margin-right:4px;}
.sl-relay .off{color:#e34d59;}
.sl-relay .empty{color:#9ca3af;text-align:center;}
</style>
HTML;
    }

    /** 当前访问地址（只保留路径部分，作为表单回发地址） */
    protected static function selfUrl()
    {
        $https  = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $path   = (string)strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
        if ($path === '') {
            $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
        }
        return $scheme . '://' . $host . $path;
    }

    protected static function maskKey($key)
    {
        if (strlen($key) <= 11) {
            return $key;
        }
        return substr($key, 0, 11) . '********';
    }

    /** 表单 nonce */
    protected static function nonce($token)
    {
        return substr(hash_hmac('sha256', (string)floor(time() / self::NONCE_WINDOW), $token), 0, 16);
    }

    /** 校验表单 nonce（容忍相邻窗口） */
    protected static function checkNonce($token, $nonce)
    {
        if ($nonce === '') {
            return false;
        }
        foreach ([0, -1, 1] as $offset) {
            $expect = substr(hash_hmac('sha256', (string)(floor(time() / self::NONCE_WINDOW) + $offset), $token), 0, 16);
            if (hash_equals($expect, $nonce)) {
                return true;
            }
        }
        return false;
    }

    protected static function e($text)
    {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }

    /** 输出整页 HTML（免登录管理页用） */
    protected static function page($title, $body)
    {
        $t = self::e($title);
        echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t}</title>
<style>
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f5f7fa;margin:0;padding:24px;}
h1{font-size:20px;margin:0 0 16px;}
</style>
</head>
<body>
<h1>{$t}</h1>
{$body}
</body>
</html>
HTML;
    }
}