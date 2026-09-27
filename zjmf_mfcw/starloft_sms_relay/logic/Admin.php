<?php
namespace addons\starloft_sms_relay\logic;

/**
 * 插件管理面板
 *
 * panel() 返回面板片段（页内导航 + 当前页），供魔方后台的插件页面（template/admin/index.php）内嵌渲染；
 * render() 输出完整页面，供插件自带的免登录管理地址使用，由配置里的「管理令牌」保护。
 * 页面用 ?page= 区分（overview 概览 / keys 密钥管理 / key 密钥详情 / logs 调用日志 / help 使用说明），
 * 表单一律回发到当前地址，保留魔方的 _plugin/_controller/_action 路由参数。
 */
class Admin
{
    /** 免登录管理页的 nonce 时间窗口（秒）：同一窗口内生成与校验一致，跨窗容忍 ±1 窗 */
    const NONCE_WINDOW = 600;

    /** 列表单次展示的日志条数 */
    const LOG_LIMIT = 100;

    /** 页内导航：page 参数 => 标题 */
    public static function tabs()
    {
        return [
            'overview' => '概览',
            'keys'     => '密钥管理',
            'logs'     => '调用日志',
            'help'     => '使用说明',
        ];
    }

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

        $base   = self::selfUrl();
        $hidden = '';
        if ($token !== null) {
            $base   = self::withQuery($base, ['token' => (string)$token]);
            $hidden = '<input type="hidden" name="token" value="' . self::e((string)$token) . '">'
                . '<input type="hidden" name="nonce" value="' . self::e(self::nonce((string)$token)) . '">';
        }

        $page = (string)($_GET['page'] ?? 'overview');
        $tabs = self::tabs();
        if ($page !== 'key' && !isset($tabs[$page])) {
            $page = 'overview';
        }

        $tableError = null;
        $tablesOk   = KeyStore::ensureTables($tableError);
        $ctx = [
            'cfg'        => $cfg,
            'base'       => $base,
            'hidden'     => $hidden,
            'tablesOk'   => $tablesOk,
            'tableError' => $tableError,
        ];

        $html  = self::style();
        $html .= '<div class="sl-relay">';
        $html .= self::navbar($base, $page);
        if ($notice !== '') {
            $html .= '<div class="notice">' . self::e($notice) . '</div>';
        }
        switch ($page) {
            case 'keys':
                $html .= self::pageKeys($ctx);
                break;
            case 'key':
                $html .= self::pageKeyDetail($ctx);
                break;
            case 'logs':
                $html .= self::pageLogs($ctx);
                break;
            case 'help':
                $html .= self::pageHelp($ctx);
                break;
            default:
                $html .= self::pageOverview($ctx);
        }
        $html .= '</div>';
        return $html;
    }

    // ==================== 页内导航 ====================

    /** 页内导航：各页签 + 回魔方后台的出口 */
    protected static function navbar($base, $page)
    {
        $h = '<div class="nav">';
        foreach (self::tabs() as $key => $label) {
            $cls = $page === $key ? ' class="active"' : '';
            $h .= '<a' . $cls . ' href="' . self::e(self::withQuery($base, ['page' => $key])) . '">' . self::e($label) . '</a>';
        }
        $h .= '<span class="spacer"></span>';
        $list = self::pluginListUrl();
        if ($list !== '') {
            $h .= '<a class="out" href="' . self::e($list) . '">插件列表</a>';
        }
        $home = self::adminRoot();
        if ($home !== '') {
            $h .= '<a class="out" href="' . self::e($home) . '">返回后台首页</a>';
        }
        $h .= '</div>';
        return $h;
    }

    // ==================== 各页内容 ====================

    /** 概览：配置状态、计费单价、统计、平台连通测试 */
    protected static function pageOverview(array $ctx)
    {
        $cfg = (array)$ctx['cfg'];

        $ready = false;
        $h = '';
        if (!empty($cfg)) {
            $apiUrl = trim((string)($cfg['api_url'] ?? ''));
            $ready  = $apiUrl !== '' && trim((string)($cfg['api_key'] ?? '')) !== ''
                && (string)($cfg['api_secret'] ?? '') !== '';
            $h .= '<div class="card"><b>平台配置</b><br>'
                . 'API 地址：' . ($apiUrl !== '' ? self::e($apiUrl) : '<span class="off">未配置</span>') . '<br>'
                . '平台密钥：' . ($ready ? '已配置' : '<span class="off">未配置（请到插件配置中填写）</span>')
                . '</div>';
            $h .= '<form class="inline" method="post" action="' . self::e($ctx['base']) . '">' . $ctx['hidden']
                . '<input type="hidden" name="action" value="test">'
                . '<button type="submit">测试平台连通/鉴权</button></form>';
        }

        $h .= self::priceCard($cfg);
        $h .= self::statCard($ctx);

        if (!empty($cfg) && $ready) {
            $h .= '<div class="card"><b>客户端怎么调</b><br>'
                . '中转地址：<code>' . self::e(self::relayUrl()) . '</code>，请求头与签名方式与星楼网络 API 一致。'
                . '详见「使用说明」。</div>';
        }
        return $h;
    }

    /** 计费单价卡片：按 Relay 的端点表逐个列单价 */
    protected static function priceCard(array $cfg)
    {
        $h = '<h2>计费单价</h2><table><thead><tr><th>端点</th><th>权限码</th><th>单价(元)</th></tr></thead><tbody>';
        foreach (Relay::endpoints() as $endpoint => $rule) {
            $key   = (string)($rule['price'] ?? 'price_other');
            $codes = implode(' / ', array_values((array)$rule['methods']));
            $h .= '<tr><td><code>' . self::e($endpoint) . '</code></td><td>' . self::e($codes) . '</td><td>'
                . Relay::money($cfg[$key] ?? 0) . '</td></tr>';
        }
        $h .= '</tbody></table>'
            . '<div class="card">单价为 0 表示该端点免费。短信发送按请求里的号码个数计费（长短信拆分条数请按最坏情况定价），'
            . '其余端点按次计费；调用成功才扣费，平台返回失败会自动退还。</div>';
        return $h;
    }

    /** 统计卡片：密钥数、余额合计、当日调用 */
    protected static function statCard(array $ctx)
    {
        if (empty($ctx['tablesOk'])) {
            return self::tableWarn($ctx);
        }
        try {
            $s = KeyStore::stats();
        } catch (\Throwable $e) {
            return '<div class="warn">统计失败：' . self::e($e->getMessage()) . '</div>';
        }
        return '<h2>当前数据</h2><table><thead><tr><th>客户密钥</th><th>预付余额合计(元)</th><th>今日调用次数</th></tr></thead><tbody>'
            . '<tr><td>' . (int)$s['keys'] . '</td><td>' . Relay::money($s['balance']) . '</td><td>'
            . (int)$s['calls_today'] . '</td></tr></tbody></table>';
    }

    /** 密钥管理：新建 + 列表（含详情入口、充值/扣减、启停/重置/删除） */
    protected static function pageKeys(array $ctx)
    {
        $h = '<h2>客户密钥</h2>';
        $h .= '<form class="card" method="post" action="' . self::e($ctx['base']) . '">' . $ctx['hidden']
            . '<input type="hidden" name="action" value="create">'
            . '<b>新建客户密钥</b><div class="row">'
            . '<input name="client_name" placeholder="客户名称" required maxlength="64">'
            . '<input name="permissions" placeholder="权限：all 或逗号分隔权限码" value="all">'
            . '<input name="remark" placeholder="备注（可选）" maxlength="255">'
            . '<button type="submit">生成密钥</button></div></form>';

        if (empty($ctx['tablesOk'])) {
            return $h . self::tableWarn($ctx);
        }
        try {
            $keys = KeyStore::listKeys();
        } catch (\Throwable $e) {
            return $h . '<div class="warn">读取密钥失败：' . self::e($e->getMessage()) . '</div>';
        }

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
                . '<td>' . self::detailLink($ctx['base'], $id)
                . self::actionForms($ctx['base'], $ctx['hidden'], $id, (int)($row['status'] ?? 0) === 1)
                . self::balanceForm($ctx['base'], $ctx['hidden'], $id) . '</td>'
                . '</tr>';
        }
        $h .= '</tbody></table>';
        return $h;
    }

    /** 密钥详情：查看完整密钥与 Secret，并可直接修改 */
    protected static function pageKeyDetail(array $ctx)
    {
        $id = (int)($_GET['id'] ?? 0);
        $h  = '<h2>密钥详情 #' . $id . '</h2>';
        $h .= '<div class="inline"><a class="btn" href="'
            . self::e(self::withQuery($ctx['base'], ['page' => 'keys', 'id' => null])) . '">返回列表</a></div>';

        if (empty($ctx['tablesOk'])) {
            return $h . self::tableWarn($ctx);
        }
        $row = null;
        try {
            $row = KeyStore::getById($id);
        } catch (\Throwable $e) {
            return $h . '<div class="warn">读取失败：' . self::e($e->getMessage()) . '</div>';
        }
        if (!$row) {
            return $h . '<div class="warn">该密钥不存在（可能已被删除）。</div>';
        }

        $h .= '<form class="card" method="post" action="' . self::e($ctx['base']) . '">' . $ctx['hidden']
            . '<input type="hidden" name="action" value="updatekey">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<b>查看 / 修改</b><div class="row">'
            . '<label class="field">客户名称<input name="client_name" value="' . self::e((string)($row['client_name'] ?? '')) . '" maxlength="64"></label>'
            . '<label class="field">权限<input name="permissions" value="' . self::e((string)($row['permissions'] ?? '')) . '" maxlength="255"></label>'
            . '<label class="field">备注<input name="remark" value="' . self::e((string)($row['remark'] ?? '')) . '" maxlength="255"></label>'
            . '</div><div class="row">'
            . '<label class="field">中转密钥 access_key<input name="access_key" value="' . self::e((string)($row['access_key'] ?? '')) . '" maxlength="64"></label>'
            . '<label class="field">Secret access_secret<input name="access_secret" value="' . self::e((string)($row['access_secret'] ?? '')) . '" maxlength="128"></label>'
            . '</div><div class="row"><button type="submit">保存修改</button></div>'
            . '<div class="tip">修改密钥或 Secret 后，客户必须同步更新，否则会验签失败。</div></form>';

        $h .= '<table><tbody>'
            . '<tr><th>状态</th><td>' . ((int)($row['status'] ?? 0) === 1 ? '启用' : '<span class="off">停用</span>') . '</td></tr>'
            . '<tr><th>余额(元)</th><td>' . Relay::money($row['balance'] ?? 0) . '</td></tr>'
            . '<tr><th>创建时间</th><td>' . self::e((string)($row['create_time'] ?? '')) . '</td></tr>'
            . '<tr><th>更新时间</th><td>' . self::e((string)($row['update_time'] ?? '')) . '</td></tr>'
            . '</tbody></table>';

        $h .= '<h2>余额调整</h2><div class="card">' . self::balanceForm($ctx['base'], $ctx['hidden'], $id) . '</div>';
        $h .= '<h2>其它操作</h2><div class="card">'
            . self::actionForms($ctx['base'], $ctx['hidden'], $id, (int)($row['status'] ?? 0) === 1) . '</div>';
        return $h;
    }

    /** 调用日志 */
    protected static function pageLogs(array $ctx)
    {
        $h = '<h2>最近调用日志（最多 ' . self::LOG_LIMIT . ' 条）</h2>';
        if (empty($ctx['tablesOk'])) {
            return $h . self::tableWarn($ctx);
        }
        try {
            $logs = KeyStore::recentLogs(self::LOG_LIMIT);
        } catch (\Throwable $e) {
            return $h . '<div class="warn">读取日志失败：' . self::e($e->getMessage()) . '</div>';
        }
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

    /** 使用说明：端点表、请求头、计费与调用示例 */
    protected static function pageHelp(array $ctx)
    {
        $cfg     = (array)$ctx['cfg'];
        $relay   = self::relayUrl();
        $host    = (string)($_SERVER['HTTP_HOST'] ?? '你的站点');
        $h  = '<h2>客户端调用方式</h2>';
        $h .= '<div class="card">中转地址（用 <code>?endpoint=</code> 指定端点）：'
            . '<code>' . self::e($relay) . '</code><br>'
            . '请求头：<code>X-Api-Key</code>（客户中转密钥）、<code>X-Sign</code>（对请求体做 HMAC-SHA256）、'
            . '<code>X-Sign-Version: hmac_sha256</code>、<code>X-Timestamp</code>（秒级时间戳，容差 300 秒）。</div>';

        $h .= '<h2>可用端点</h2><table><thead><tr><th>端点</th><th>方法</th><th>所需权限码</th><th>单价(元)</th></tr></thead><tbody>';
        foreach (Relay::endpoints() as $endpoint => $rule) {
            $key = (string)($rule['price'] ?? 'price_other');
            foreach ((array)$rule['methods'] as $method => $code) {
                $h .= '<tr><td><code>' . self::e($endpoint) . '</code>'
                    . (!empty($rule['needs_id']) ? '（另带 <code>&amp;id=</code>）' : '')
                    . '</td><td>' . self::e($method) . '</td><td>' . self::e($code) . '</td><td>'
                    . Relay::money($cfg[$key] ?? 0) . '</td></tr>';
            }
        }
        $h .= '</tbody></table>';

        $h .= '<h2>调用示例（以发送短信为例）</h2><div class="card"><pre>'
            . 'BODY=\'{"phone_number_set":["13800000000"],"template_id":"1000001","params":["123456"]}\'' . "\n"
            . 'TS=$(date +%s)' . "\n"
            . 'SIGN=$(printf %s "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk \'{print $2}\')' . "\n"
            . 'curl -sS -X POST "https://' . self::e($host) . '/addons/starloft_sms_relay/apiRelay?endpoint=sms/send" \\' . "\n"
            . '  -H "X-Api-Key: $KEY" -H "X-Sign: $SIGN" -H "X-Sign-Version: hmac_sha256" -H "X-Timestamp: $TS" \\' . "\n"
            . '  -H "Content-Type: application/json" -d "$BODY"</pre></div>';

        $h .= '<div class="card"><b>计费与对账</b><br>验签通过 → 按端点单价从预付余额预扣（余额不足直接返回 402）→ 转发到平台 → '
            . '平台返回非成功（<code>code != 0</code>）时把预扣金额原路退还；响应头带 <code>X-Relay-Price</code>、'
            . '<code>X-Relay-Balance</code>、<code>X-Relay-Units</code>，每次调用的扣费与余额也记在「调用日志」里。</div>';
        return $h;
    }

    // ==================== 表单处理 ====================

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
        try {
            return self::handleAction($action);
        } catch (\Throwable $e) {
            return '操作失败：' . $e->getMessage();
        }
    }

    /** 执行管理动作，返回提示文案 */
    protected static function handleAction($action)
    {
        $id = (int)($_POST['id'] ?? 0);
        switch ($action) {
            case 'create':
                $row = KeyStore::createKey(
                    (string)($_POST['client_name'] ?? ''),
                    (string)($_POST['permissions'] ?? 'all'),
                    (string)($_POST['remark'] ?? '')
                );
                return '已创建客户密钥：' . $row['access_key'] . '（Secret 仅本次显示，请立即复制：' . $row['access_secret'] . '）';
            case 'updatekey':
                $fields = [
                    'client_name'   => trim((string)($_POST['client_name'] ?? '')),
                    'permissions'   => trim((string)($_POST['permissions'] ?? '')),
                    'remark'        => trim((string)($_POST['remark'] ?? '')),
                    'access_key'    => trim((string)($_POST['access_key'] ?? '')),
                    'access_secret' => trim((string)($_POST['access_secret'] ?? '')),
                ];
                if (!preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $fields['access_key'])) {
                    return '保存失败：中转密钥只能是 8-64 位的字母、数字、下划线或短横线';
                }
                if (strlen($fields['access_secret']) < 16) {
                    return '保存失败：Secret 至少 16 位';
                }
                if ($fields['permissions'] === '') {
                    $fields['permissions'] = 'all';
                }
                KeyStore::updateKey($id, $fields);
                return '已保存密钥 #' . $id;
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
        return '未识别的操作';
    }

    // ==================== 区块 ====================

    /** 数据表不可用时的提示（含可手工执行的建表 SQL） */
    protected static function tableWarn(array $ctx)
    {
        return '<div class="warn"><b>数据表不可用：</b>' . self::e((string)$ctx['tableError'])
            . '<p>中转仍可工作，但密钥管理不可用。请让 DBA 执行下面的 SQL 后刷新本页：</p>'
            . '<pre>' . self::e(KeyStore::keyTableSql()) . "\n\n" . self::e(KeyStore::logTableSql()) . '</pre></div>';
    }

    /** 列表里的「详情 / 修改」入口 */
    protected static function detailLink($base, $id)
    {
        return '<div class="inline"><a class="btn" href="'
            . self::e(self::withQuery($base, ['page' => 'key', 'id' => (int)$id])) . '">详情 / 修改</a></div>';
    }

    /** 单行操作按钮（各自独立表单） */
    protected static function actionForms($base, $hidden, $id, $enabled)
    {
        $fields = $hidden . '<input type="hidden" name="id" value="' . (int)$id . '">';
        $btn = function ($action, $label, $confirm = '') use ($base, $fields) {
            return '<form class="inline" method="post" action="' . self::e($base) . '"'
                . ($confirm !== '' ? ' onsubmit="return confirm(\'' . self::e($confirm) . '\');"' : '') . '>'
                . $fields . '<input type="hidden" name="action" value="' . self::e($action) . '">'
                . '<button type="submit">' . self::e($label) . '</button></form>';
        };
        $out  = $btn($enabled ? 'disable' : 'enable', $enabled ? '停用' : '启用');
        $out .= $btn('reset', '重置Secret', '重置后旧 Secret 立即失效，确认？');
        $out .= $btn('delete', '删除', '删除后客户将无法调用，确认？');
        return $out;
    }

    /** 余额操作（充值/扣减），金额为空或非正数时不提交 */
    protected static function balanceForm($base, $hidden, $id)
    {
        return '<form class="inline" method="post" action="' . self::e($base) . '"'
            . ' onsubmit="if(!(parseFloat(this.amount.value)>0)){alert(\'请输入大于 0 的金额\');return false;}">'
            . $hidden . '<input type="hidden" name="id" value="' . (int)$id . '">'
            . '<input name="amount" type="number" step="0.01" min="0.01" placeholder="金额" style="width:78px;padding:4px;">'
            . '<button type="submit" name="action" value="recharge">充值</button>'
            . '<button type="submit" name="action" value="deduct">扣减</button></form>';
    }

    // ==================== 地址与工具 ====================

    /** 当前访问地址（保留魔方路由参数，去掉 token） */
    protected static function selfUrl()
    {
        $parts = explode('?', (string)($_SERVER['REQUEST_URI'] ?? ''), 2);
        $path  = $parts[0] !== '' ? $parts[0] : (string)($_SERVER['SCRIPT_NAME'] ?? '/');
        $query = [];
        if (isset($parts[1]) && $parts[1] !== '') {
            parse_str($parts[1], $query);
        }
        unset($query['token']);
        return self::origin() . $path . ($query ? '?' . http_build_query($query) : '');
    }

    /** 客户调用的中转地址（免登录，客户按平台签名规则调用） */
    protected static function relayUrl()
    {
        return self::origin() . '/addons/starloft_sms_relay/apiRelay?endpoint=sms/send';
    }

    /** 魔方后台首页地址（当前地址形如 /xxx/addons 时取其上级，否则为空） */
    protected static function adminRoot()
    {
        $path = rtrim((string)strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'), '/');
        if (preg_match('#^(.*)/addons$#', $path, $m)) {
            return self::origin() . $m[1] . '/';
        }
        return '';
    }

    /** 魔方插件列表地址（当前地址形如 /xxx/addons 时返回其本身） */
    protected static function pluginListUrl()
    {
        $path = rtrim((string)strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'), '/');
        if (preg_match('#^(.*)/addons$#', $path, $m)) {
            return self::origin() . $path;
        }
        return '';
    }

    /** 协议 + 主机 */
    protected static function origin()
    {
        $https  = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $host   = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    /** 在 URL 上叠加查询参数（值为 null 表示删除该参数） */
    protected static function withQuery($url, array $params)
    {
        $parts = explode('?', $url, 2);
        $query = [];
        if (isset($parts[1]) && $parts[1] !== '') {
            parse_str($parts[1], $query);
        }
        foreach ($params as $k => $v) {
            if ($v === null) {
                unset($query[$k]);
            } else {
                $query[$k] = $v;
            }
        }
        return $parts[0] . ($query ? '?' . http_build_query($query) : '');
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

    /** 面板样式（片段自带，避免依赖魔方后台样式） */
    protected static function style()
    {
        return <<<HTML
<style>
.sl-relay{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;color:#1f2937;font-size:13px;}
.sl-relay h2{font-size:16px;margin:26px 0 10px;}
.sl-relay .nav{display:flex;align-items:center;gap:4px;border-bottom:1px solid #e5e7eb;padding-bottom:0;margin-bottom:14px;}
.sl-relay .nav a{display:inline-block;padding:9px 14px;color:#4b5563;text-decoration:none;border-bottom:2px solid transparent;}
.sl-relay .nav a:hover{color:#1cd5c7;}
.sl-relay .nav a.active{color:#1cd5c7;border-bottom-color:#1cd5c7;font-weight:600;}
.sl-relay .nav .spacer{flex:1;}
.sl-relay .nav a.out{color:#8b95a5;font-size:12px;}
.sl-relay table{width:100%;border-collapse:collapse;background:#fff;font-size:13px;}
.sl-relay th,.sl-relay td{border:1px solid #e5e7eb;padding:8px;text-align:left;vertical-align:top;}
.sl-relay th{background:#f9fafb;font-weight:600;width:auto;}
.sl-relay code{background:#f3f4f6;padding:1px 4px;border-radius:3px;word-break:break-all;}
.sl-relay pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;overflow:auto;font-size:12px;}
.sl-relay .notice{background:#ecfdf5;border:1px solid #10b981;color:#065f46;padding:10px 12px;border-radius:6px;margin-bottom:14px;word-break:break-all;}
.sl-relay .warn{background:#fffbeb;border:1px solid #f59e0b;color:#92400e;padding:10px 12px;border-radius:6px;margin-bottom:14px;}
.sl-relay .warn pre{background:#fff;color:#92400e;padding:10px;overflow:auto;font-size:12px;}
.sl-relay .card{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:14px;line-height:1.9;}
.sl-relay .tip{color:#8b95a5;font-size:12px;margin-top:8px;}
.sl-relay .row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;align-items:flex-end;}
.sl-relay .row input{flex:1;min-width:170px;padding:7px 9px;border:1px solid #d1d5db;border-radius:4px;}
.sl-relay .field{display:flex;flex-direction:column;gap:4px;flex:1;min-width:220px;color:#6b7280;font-size:12px;}
.sl-relay button{padding:6px 12px;border:1px solid #1cd5c7;background:#1cd5c7;color:#fff;border-radius:4px;cursor:pointer;font-size:12px;}
.sl-relay button:hover{opacity:.9;}
.sl-relay .btn{display:inline-block;padding:6px 12px;border:1px solid #d1d5db;background:#fff;color:#374151;border-radius:4px;text-decoration:none;font-size:12px;}
.sl-relay .inline{display:inline-block;margin-right:4px;}
.sl-relay .off{color:#e34d59;}
.sl-relay .empty{color:#9ca3af;text-align:center;}
</style>
HTML;
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