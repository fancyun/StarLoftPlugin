<?php
namespace lib\sms;

/**
 * 星楼网络（StarLoft）短信通道
 *
 * 对接平台 POST /v1/sms/send：按平台模板 ID 发送，模板参数按模板占位符顺序上送。
 * 鉴权：X-Api-Key + X-Sign（HMAC-SHA256(api_secret, 原始请求体)）+ X-Sign-Version + X-Timestamp。
 *
 * 与其它通道的差异：$template 传的是「平台短信模板 ID」（不是模板文案），
 * 短信文案与变量顺序在星楼网络平台侧维护，本通道只按顺序上送参数值。
 */
class StarLoft
{
    private $key;
    private $secret;
    private $url;

    /**
     * @param string $key    API Key（后台「短信接口设置 → AppId」）
     * @param string $secret API Secret（后台「短信接口设置 → AppKey」）
     * @param string $url    API 地址，留空默认 https://api.starloft.cn
     */
    function __construct($key, $secret, $url = ''){
        $this->key = $key;
        $this->secret = $secret;
        $this->url = $url ? rtrim($url, '/') : 'https://api.starloft.cn';
    }

    /**
     * 发送短信
     *
     * @param string $phone    手机号
     * @param array  $param    模板参数（关联数组，按顺序取值；验证码场景为 ['code'=>验证码]）
     * @param string $template 平台短信模板 ID
     * @param string $sign     短信签名内容（不含【】；留空由平台自动取账号最新一条已通过的签名）
     * @return true|string     成功返回 true，失败返回错误信息
     */
    public function send($phone, $param, $template, $sign){
        if(empty($this->key) || empty($this->secret))return '未配置星楼网络API Key / API Secret';
        if(empty($template))return '未配置星楼网络短信模板ID';

        $body = json_encode([
            'phone_number_set' => [(string)$phone],
            'template_id' => (string)$template,
            'template_params' => array_values((array)$param),
            'sign_name' => (string)$sign,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type: application/json',
            'X-Api-Key: '.$this->key,
            'X-Sign: '.hash_hmac('sha256', $body, $this->secret),
            'X-Sign-Version: hmac_sha256',
            'X-Timestamp: '.time(),
        ];
        $resp = get_curl($this->url.'/v1/sms/send', $body, 0, 0, 0, 0, 0, $headers);
        $arr = json_decode((string)$resp, true);
        if(!is_array($arr)){
            return '接口无响应或返回内容无法解析'.($resp ? ('：'.mb_substr(strip_tags((string)$resp), 0, 200)) : '');
        }
        if(isset($arr['code']) && $arr['code'] == 0){
            return true;
        }
        return !empty($arr['message']) ? (string)$arr['message'] : '短信发送失败';
    }
}