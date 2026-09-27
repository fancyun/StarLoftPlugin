<?php
/*
 * 插件前台页面
 *
 * 系统在会员中心「插件」导航下生成的插件入口默认指向本文件，只说明中转接口的调用方式，
 * 不读取也不输出本站点的平台密钥。
 */
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
$base = $host !== '' ? (($https ? 'https' : 'http') . '://' . $host) : '';
$relayUrl = $base . '/addon/starloft_sms_relay/index/apiRelay?endpoint=sms/send';
?>
<div style="font-family:-apple-system,'PingFang SC','Microsoft YaHei',sans-serif;color:#1f2937;font-size:14px;line-height:1.9;">
    <h3 style="font-size:16px;margin:0 0 12px;">StarLoft 短信中转 · 接口说明</h3>
    <p>本站点把 StarLoft 短信接口以中转方式提供：你需要在服务端使用站点下发的「中转密钥」（access_key 与 access_secret），
        按 StarLoft 平台同一套签名规则调用本站点的中转地址，本站点校验后转发到星楼网络并原样返回结果。</p>
    <p><b>中转地址：</b><code><?php echo htmlspecialchars($relayUrl, ENT_QUOTES, 'UTF-8'); ?></code></p>
    <p><b>请求头：</b><code>X-Api-Key</code>（中转密钥）、<code>X-Sign</code>（对请求体做 HMAC-SHA256）、
        <code>X-Sign-Version: hmac_sha256</code>、<code>X-Timestamp</code>（秒级时间戳，容差 300 秒）。</p>
    <p><b>可用端点：</b>短信发送 <code>sms/send</code>、签名 <code>sms/signs</code>、模板 <code>sms/templates</code>、
        模板详情 <code>sms/templates/:id</code>（带 <code>&amp;id=</code>）、发送回执 <code>sms/report</code>、
        上行回复 <code>sms/replies</code>。用 <code>?endpoint=</code> 指定。</p>
    <p><b>计费：</b>调用成功才从预付余额扣减，平台返回失败会自动退还；余额不足时返回 402，请联系站点管理员充值。</p>
    <p><b>密钥与余额：</b>由站点管理员在后台的「插件 → StarLoft 短信中转」中生成与充值。</p>
</div>