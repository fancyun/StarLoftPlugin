<?php
namespace lib;

use Exception;

/**
 * 星楼网络（StarLoft）实名认证（人脸核身）通道
 *
 * 对接平台 /v1/fv：
 *   - POST /v1/fv/auth    创建核验订单，返回 biz_no（平台流水号）与 site_url（人脸核身承接页）
 *   - POST /v1/fv/result  查询/校对结果，status：1-通过 2-未通过 4-处理中
 *   - 异步通知            平台按 biz_no/cost/result_code/result_message/status 排序后 HMAC-SHA256 签名
 *
 * 鉴权：X-Api-Key + X-Sign（HMAC-SHA256(api_secret, 原始请求体)）+ X-Sign-Version + X-Timestamp。
 */
class StarLoftCertify
{
    private $key;
    private $secret;
    private $url;

    /**
     * @param string $key    API Key（后台「实名认证接口配置 → API Key」）
     * @param string $secret API Secret（后台「实名认证接口配置 → API Secret」）
     * @param string $url    API 地址，留空默认 https://api.starloft.cn
     */
    function __construct($key, $secret, $url = ''){
        $this->key = $key;
        $this->secret = $secret;
        $this->url = $url ? rtrim($url, '/') : 'https://api.starloft.cn';
    }

    /**
     * 发起实名认证（有源人脸核身，需姓名 + 身份证号）
     *
     * @param string $cert_name  真实姓名
     * @param string $cert_no    身份证号
     * @param string $return_url 前端回跳地址（用户在平台完成核验后返回）
     * @param string $notify_url 后端异步通知地址（平台推送核验结果）
     * @param string $extra_data 业务扩展数据（原样带回，这里放商户 uid）
     * @return array ['biz_no'=>平台流水号, 'site_url'=>核身承接页, 'expired_time'=>到期时间]
     * @throws Exception
     */
    public function initialize($cert_name, $cert_no, $return_url, $notify_url, $extra_data = ''){
        $data = $this->request('/v1/fv/auth', [
            'name' => (string)$cert_name,
            'id_card' => (string)$cert_no,
            'return_url' => (string)$return_url,
            'notify_url' => (string)$notify_url,
            'biz_extra_data' => (string)$extra_data,
        ]);
        if(empty($data['biz_no']) || empty($data['site_url'])){
            throw new Exception('平台未返回认证流水号或认证链接');
        }
        return $data;
    }

    /**
     * 查询/校对核验结果
     *
     * @param string $biz_no 平台流水号（发起时返回）
     * @param int    $sync   1=立即校对一次（用户点击「我已完成核验」），0=普通查询
     * @return array ['biz_no','status'(1通过/2未通过/4处理中),'result_code','result_message']
     * @throws Exception
     */
    public function query($biz_no, $sync = 0){
        $post = ['biz_no' => (string)$biz_no];
        if(intval($sync) == 1)$post['sync'] = 1;
        $data = $this->request('/v1/fv/result', $post);
        $status = isset($data['status']) ? intval($data['status']) : 4;
        if($status != 1 && $status != 2)$status = 4;
        $data['status'] = $status;
        return $data;
    }

    /**
     * 校验平台异步通知的 HMAC 签名
     * （与平台一致：biz_no/cost/result_code/result_message/status 按字段名排序后 k=v&k=v）
     */
    public function verifyNotifySign($data, $sign){
        if(!is_array($data) || !is_string($sign) || $sign === '' || $this->secret === ''){
            return false;
        }
        $fields = [
            'biz_no' => (string)($data['biz_no'] ?? ''),
            'cost' => sprintf('%.2f', (float)($data['cost'] ?? 0)),
            'result_code' => (string)($data['result_code'] ?? ''),
            'result_message' => (string)($data['result_message'] ?? ''),
            'status' => (string)intval($data['status'] ?? 0),
        ];
        ksort($fields);
        $canonical = '';
        foreach($fields as $k => $v){
            $canonical .= ($canonical === '' ? '' : '&').$k.'='.$v;
        }
        return hash_equals(hash_hmac('sha256', $canonical, $this->secret), strtolower(trim($sign)));
    }

    /**
     * 带签名的 API 请求；业务失败抛异常，成功返回 data 数组
     */
    private function request($path, array $post){
        if(empty($this->key) || empty($this->secret)){
            throw new Exception('未配置星楼网络API Key / API Secret');
        }
        $body = json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Content-Type: application/json',
            'X-Api-Key: '.$this->key,
            'X-Sign: '.hash_hmac('sha256', $body, $this->secret),
            'X-Sign-Version: hmac_sha256',
            'X-Timestamp: '.time(),
        ];
        $resp = get_curl($this->url.$path, $body, 0, 0, 0, 0, 0, $headers);
        $arr = json_decode((string)$resp, true);
        if(!is_array($arr)){
            throw new Exception('接口无响应或返回内容无法解析');
        }
        if(isset($arr['code']) && $arr['code'] == 0){
            return isset($arr['data']) && is_array($arr['data']) ? $arr['data'] : [];
        }
        throw new Exception(!empty($arr['message']) ? (string)$arr['message'] : '接口调用失败');
    }
}