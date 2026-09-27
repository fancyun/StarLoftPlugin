<?php
namespace addons\starloft_sms_relay\logic;

/**
 * API 中转（含计费）
 *
 * 客户持站点下发的「中转密钥」按平台同一套规则签名调用本站点中转端点；
 * 插件校验通过后：按端点单价从客户预付余额里**预扣**，再用站点配置的平台密钥重新签名转发到 StarLoft，
 * 原样回传状态码与响应体；平台返回非成功（code != 0）时把预扣金额**原路退还**。
 */
class Relay
{
    /** 客户签名时间戳容差（秒），与平台一致 */
    const TIMESTAMP_TOLERANCE = 300;

    /** 转发到平台的超时（秒） */
    const TIMEOUT = 60;

    /**
     * 可中转的端点白名单：endpoint 参数 => 平台路径 + 各 HTTP 方法所需权限码 + 计价配置键
     *
     * 路径与权限码与平台 /v1/* 契约一一对应；客户只能按本表取值，不能传任意路径。
     */
    public static function endpoints()
    {
        return [
            'sms/send'          => ['path' => '/v1/sms/send',      'methods' => ['POST' => 'sms_send'], 'price' => 'price_sms_send'],
            'sms/signs'         => ['path' => '/v1/sms/signs',     'methods' => ['POST' => 'sms_sign']],
            'sms/templates'     => ['path' => '/v1/sms/templates', 'methods' => ['POST' => 'sms_template_create']],
            'sms/templates/:id' => [
                'path'     => '/v1/sms/templates/%s',
                'methods'  => ['GET' => 'sms_template_get', 'PUT' => 'sms_template_update', 'DELETE' => 'sms_template_delete'],
                'needs_id' => true,
            ],
            'sms/report'        => ['path' => '/v1/sms/report',    'methods' => ['POST' => 'sms_report']],
            'sms/replies'       => ['path' => '/v1/sms/replies',   'methods' => ['POST' => 'sms_replies']],
        ];
    }

    /**
     * 处理一次中转请求：校验 → 预扣 → 转发 → 结算 → 回传 → 记日志（本方法会结束请求）
     *
     * 带 X-Api-Key 的每次尝试都会落一条日志（含鉴权失败/余额不足），便于站点排查与对账；
     * 未带密钥的扫描流量不落日志，避免污染。
     */
    public static function handle(array $cfg)
    {
        $started  = microtime(true);
        $method   = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST'));
        $body     = (string)file_get_contents('php://input');
        $endpoint = trim((string)($_GET['endpoint'] ?? $_SERVER['HTTP_X_ENDPOINT'] ?? ''));

        $apiKey    = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
        $sign      = trim((string)($_SERVER['HTTP_X_SIGN'] ?? ''));
        $signVer   = trim((string)($_SERVER['HTTP_X_SIGN_VERSION'] ?? ''));
        $timestamp = trim((string)($_SERVER['HTTP_X_TIMESTAMP'] ?? ''));

        $ctx = [
            'cfg'      => $cfg,
            'method'   => $method,
            'endpoint' => $endpoint,
            'api_key'  => $apiKey,
            'started'  => $started,
        ];

        if ($apiKey === '' || $sign === '' || $signVer === '' || $timestamp === '') {
            self::reject($ctx, null, 401, 'missing required headers');
        }
        if ($signVer !== 'hmac_sha256') {
            self::reject($ctx, null, 400, 'unsupported sign version');
        }

        $map = self::endpoints();
        if ($endpoint === '' || !isset($map[$endpoint])) {
            self::reject($ctx, null, 400, 'unknown endpoint（用 ?endpoint=sms/send 指定，可选值见插件 README）');
        }
        $rule = $map[$endpoint];
        if (!isset($rule['methods'][$method])) {
            self::reject($ctx, null, 405, 'method not allowed for this endpoint');
        }

        $client  = null;
        $dbError = null;
        try {
            $client = KeyStore::getByAccessKey($apiKey);
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }
        if ($dbError !== null) {
            self::reject($ctx, null, 500, '密钥表不可用：' . $dbError);
        }
        if (!$client) {
            self::reject($ctx, null, 401, 'invalid api key');
        }
        if ((int)$client['status'] !== 1) {
            self::reject($ctx, $client, 403, 'api key disabled');
        }
        if (abs(time() - (int)$timestamp) > self::TIMESTAMP_TOLERANCE) {
            self::reject($ctx, $client, 401, 'invalid or expired timestamp');
        }
        $expect = hash_hmac('sha256', $body, (string)$client['access_secret']);
        if (!hash_equals($expect, strtolower($sign))) {
            self::reject($ctx, $client, 401, 'invalid signature');
        }

        $need = (string)$rule['methods'][$method];
        if (!self::clientAllows((string)$client['permissions'], $need)) {
            self::reject($ctx, $client, 403, '密钥无权调用该接口（' . $need . '）');
        }
        $whitelist = trim((string)($cfg['endpoint_whitelist'] ?? ''));
        if ($whitelist !== '' && !in_array($endpoint, array_filter(array_map('trim', explode(',', $whitelist))), true)) {
            self::reject($ctx, $client, 403, '该端点未开启中转');
        }
        $quota = (int)($cfg['daily_quota'] ?? 0);
        if ($quota > 0 && KeyStore::countToday($apiKey) >= $quota) {
            self::reject($ctx, $client, 429, '超过当日调用上限（' . $quota . '）');
        }

        $apiUrl         = rtrim(trim((string)($cfg['api_url'] ?? '')), '/');
        $platformKey    = trim((string)($cfg['api_key'] ?? ''));
        $platformSecret = (string)($cfg['api_secret'] ?? '');
        if ($apiUrl === '' || $platformKey === '' || $platformSecret === '') {
            self::reject($ctx, $client, 500, '插件未配置平台 API 地址/密钥，请到插件配置中填写');
        }

        $path = (string)$rule['path'];
        if (!empty($rule['needs_id'])) {
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '' || !ctype_digit($id)) {
                self::reject($ctx, $client, 400, '缺少合法的 id 参数（?endpoint=sms/templates/:id&id=123）');
            }
            $path = sprintf($path, $id);
        }

        // 预扣：余额不足直接拒绝；扣费本身出错按系统错误报
        list($units, , $cost) = self::estimate($endpoint, $body, $cfg);
        $charged = 0.0;
        if ($cost > 0) {
            $chargeError = null;
            list($ok, $balance) = KeyStore::charge($client['id'], $cost, $chargeError);
            if ($chargeError !== null) {
                self::reject($ctx, $client, 500, '扣费失败（密钥表缺 balance 列？请重装插件或执行管理页给出的 SQL）：' . $chargeError);
            }
            if (!$ok) {
                self::reject($ctx, $client, 402,
                    '余额不足，请联系站点管理员充值（当前余额 ' . self::money($balance) . ' 元，本次需 ' . self::money($cost) . ' 元）');
            }
            $charged = $cost;
        }

        list($status, $raw, $err) = self::forward($apiUrl, $platformKey, $platformSecret, $method, $path, $body);
        if ($err !== '') {
            $status = 504;
            $raw    = json_encode(['code' => 504, 'message' => '转发失败：' . $err], JSON_UNESCAPED_UNICODE);
        }
        if ($status <= 0) {
            $status = 502;
        }

        // 结算：平台未成功（code != 0，含转发失败）则把预扣金额原路退还
        $decoded = json_decode((string)$raw, true);
        $code    = is_array($decoded) ? (int)($decoded['code'] ?? -1) : -1;
        if ($charged > 0 && $code !== 0) {
            KeyStore::refund($client['id'], $charged);
            $charged = 0.0;
        }
        $balanceAfter = KeyStore::balanceOf($client['id']);

        self::writeLog($ctx, $client, $status, $code,
            is_array($decoded) ? (string)($decoded['message'] ?? '') : (string)$raw, $charged, $balanceAfter);

        if (!headers_sent()) {
            header('X-Relay-Price: ' . self::money($charged));
            header('X-Relay-Balance: ' . self::money($balanceAfter));
            header('X-Relay-Units: ' . (int)$units);
        }
        self::output($status, (string)$raw);
    }

    /**
     * 计费预估：返回 [计费单位数, 单价, 应扣金额]
     *
     * 短信发送按请求里的号码个数计费（长短信拆分条数不在此估算，站点单价需覆盖最坏情况）；
     * 其余端点按次计费（末配置单价默认为 0，即免费）。
     */
    public static function estimate($endpoint, $body, array $cfg)
    {
        $map   = self::endpoints();
        $rule  = isset($map[$endpoint]) ? $map[$endpoint] : [];
        $key   = (string)($rule['price'] ?? 'price_other');
        $unit  = round((float)($cfg[$key] ?? 0), 2);
        $units = 1;
        if ($endpoint === 'sms/send') {
            $data  = json_decode((string)$body, true);
            $list  = (is_array($data) && is_array($data['phone_number_set'] ?? null)) ? $data['phone_number_set'] : [];
            $units = count($list) > 0 ? count($list) : 1;
        }
        return [$units, $unit, round($unit * $units, 2)];
    }

    /**
     * 测试平台连通与鉴权：返回 [bool ok, string 说明]
     *
     * 用结果查询端点探活：401/403 说明平台密钥有问题，其余（如「订单不存在」）说明连通且鉴权通过。
     */
    public static function testPlatform(array $cfg)
    {
        $apiUrl = rtrim(trim((string)($cfg['api_url'] ?? '')), '/');
        $key    = trim((string)($cfg['api_key'] ?? ''));
        $secret = (string)($cfg['api_secret'] ?? '');
        if ($apiUrl === '' || $key === '' || $secret === '') {
            return [false, '未配置平台 API 地址/密钥'];
        }
        list($status, $raw, $err) = self::forward($apiUrl, $key, $secret, 'POST', '/v1/sms/report', '{"biz_no":"0"}');
        if ($err !== '') {
            return [false, '连接失败：' . $err];
        }
        if ($status === 401 || $status === 403) {
            return [false, '平台鉴权失败（HTTP ' . $status . '）：' . $raw];
        }
        return [true, '平台连通且鉴权通过（HTTP ' . $status . '）：' . $raw];
    }

    /**
     * 客户密钥是否放行某权限码（all 放行全部）
     */
    public static function clientAllows($permissions, $need)
    {
        $p = strtolower(trim((string)$permissions));
        if ($p === '' || $p === 'all') {
            return true;
        }
        foreach (explode(',', $p) as $one) {
            if (trim($one) === strtolower((string)$need)) {
                return true;
            }
        }
        return false;
    }

    /** 金额格式化（两位小数，不带千分位） */
    public static function money($amount)
    {
        return number_format(round((float)$amount, 2), 2, '.', '');
    }

    /**
     * 用平台密钥签名转发，返回 [HTTP 状态码, 响应体, 错误]
     */
    protected static function forward($apiUrl, $apiKey, $apiSecret, $method, $path, $body)
    {
        $signed  = in_array($method, ['POST', 'PUT'], true) ? (string)$body : '';
        $headers = [
            'Content-Type: application/json',
            'X-Api-Key: ' . $apiKey,
            'X-Sign: ' . hash_hmac('sha256', $signed, $apiSecret),
            'X-Sign-Version: hmac_sha256',
            'X-Timestamp: ' . time(),
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (in_array($method, ['POST', 'PUT'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
        }

        $raw    = curl_exec($ch);
        $err    = (string)curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $raw === false ? '' : (string)$raw, $err];
    }

    /**
     * 拒绝请求：先落日志（带密钥的尝试才记），再返回错误响应
     */
    protected static function reject(array $ctx, $client, $status, $message)
    {
        self::writeLog($ctx, $client, $status, $status, $message);
        self::fail($status, $message);
    }

    /**
     * 写调用日志：未带 X-Api-Key 的请求不记（扫描流量），日志开关关闭时不记
     */
    protected static function writeLog(array $ctx, $client, $httpStatus, $code, $message, $price = 0.0, $balanceAfter = 0.0)
    {
        if ((int)($ctx['cfg']['log_enabled'] ?? 1) !== 1) {
            return;
        }
        if ((string)$ctx['api_key'] === '') {
            return;
        }
        KeyStore::logCall([
            'access_key'    => is_array($client) ? (string)$client['access_key'] : (string)$ctx['api_key'],
            'client_name'   => is_array($client) ? (string)$client['client_name'] : '',
            'endpoint'      => (string)$ctx['endpoint'],
            'method'        => (string)$ctx['method'],
            'http_status'   => (int)$httpStatus,
            'code'          => (int)$code,
            'message'       => mb_substr((string)$message, 0, 200),
            'price'         => round((float)$price, 2),
            'balance_after' => round((float)$balanceAfter, 2),
            'cost_ms'       => (int)round((microtime(true) - (float)$ctx['started']) * 1000),
            'ip'            => self::clientIp(),
            'create_time'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** 客户端 IP（取 X-Forwarded-For 最左值，仅用于日志） */
    protected static function clientIp()
    {
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $parts = explode(',', $xff);
            $ip    = trim($parts[0]);
            if ($ip !== '') {
                return mb_substr($ip, 0, 45);
            }
        }
        return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /** 返回错误响应并结束请求 */
    protected static function fail($status, $message)
    {
        self::output((int)$status, json_encode(['code' => (int)$status, 'message' => $message], JSON_UNESCAPED_UNICODE));
    }

    /** 输出响应并结束请求（中转端点原样回传平台响应，不再走框架渲染） */
    protected static function output($status, $body)
    {
        if (!headers_sent()) {
            http_response_code((int)$status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo $body;
        exit;
    }
}