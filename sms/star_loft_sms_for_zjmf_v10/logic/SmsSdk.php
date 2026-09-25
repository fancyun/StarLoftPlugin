<?php
namespace sms\star_loft_sms_for_zjmf_v10\logic;

/**
 * StarLoft 星楼网络 SMS SDK
 *
 * 用于对接「星楼网络」平台短信服务（SMS）的 SDK 类。
 * 所有请求使用 API Key + HMAC-SHA256 签名鉴权，请求头：
 *   X-Api-Key / X-Sign=hex(HMAC-SHA256(api_secret, 原始请求体)) /
 *   X-Sign-Version: hmac_sha256 / X-Timestamp
 *
 * 接口前缀统一为 /v1/sms（API 域名为 api.starloft.cn）。
 *
 * @author StarLoft
 * @version 3.0.0
 */
class SmsSdk
{
    /** @var string API基础URL */
    private $apiUrl;

    /** @var string API Key */
    private $apiKey;

    /** @var string API Secret */
    private $apiSecret;

    /** @var int 请求超时时间（秒） */
    private $timeout = 30;

    /**
     * 错误分类常量（用于上层根据语义做不同动作）
     */
    const ERR_CAT_SUCCESS      = 'success';      // 发送成功(code=0)
    const ERR_CAT_NO_BALANCE   = 'no_balance';   // 商户余额/额度不足(不可重试硬错误)
    const ERR_CAT_AUTH         = 'auth';         // AppKey/签名/权限配置错误
    const ERR_CAT_PARAM        = 'param';        // 调用参数错误(字段格式/缺失等业务无关)
    const ERR_CAT_TEMP         = 'temp';         // 临时/网络/服务器错误(可重试)
    const ERR_CAT_UNKNOWN      = 'unknown';

    /**
     * 根据接口返回判断错误分类
     *
     * 兼容两种返回字段:
     *   - 规范化(code/message): SDK 在 request() 末尾会把上游 result_* 双写进来
     *   - 原生(result_code/result_message): 上层直接拿接口返回值判断时也能走通
     */
    public static function classifyError($result)
    {
        if (!is_array($result)) {
            return self::ERR_CAT_UNKNOWN;
        }
        $rawCode    = self::pickFirst($result, ['code', 'result_code', 'status'], '');
        $rawMessage = (string)self::pickFirst($result, ['message', 'result_message', 'msg'], '');
        if (is_array($result['data'] ?? null)) {
            if ($rawCode === '' || $rawCode === null) {
                $rawCode = self::pickFirst($result['data'], ['code', 'result_code', 'status'], '');
            }
            if ($rawMessage === '') {
                $rawMessage = (string)self::pickFirst($result['data'], ['message', 'result_message', 'msg'], '');
            }
        }

        $intCode = (int)$rawCode;
        if ($rawCode === 0 || $rawCode === '0' || $intCode === 1000 || $rawCode === 1000
            || in_array(strtoupper($rawMessage), ['SUCCESS', '成功', '发送成功'], true)) {
            return self::ERR_CAT_SUCCESS;
        }

        $msg = strtolower($rawMessage);
        $codeIn = function (array $arr) use ($rawCode, $intCode) {
            foreach ($arr as $v) {
                if ($rawCode === $v) return true;
                if ((string)$intCode === (string)$v) return true;
            }
            return false;
        };

        // 余额/额度不足
        if ($codeIn([4003, 4004, 4029, 1004, 402, 1006, 1015])
            || strpos($msg, '余额') !== false
            || strpos($msg, 'balance') !== false
            || strpos($msg, 'insufficient') !== false
            || strpos($msg, '额度') !== false
            || (strpos($msg, '不足') !== false && strpos($msg, '次数') === false)) {
            return self::ERR_CAT_NO_BALANCE;
        }
        // 鉴权/签名
        if ($codeIn([401, 403, 4010, 4011, 4013, 1001, 1002, 1003])
            || strpos($msg, 'sign') !== false
            || strpos($msg, '签名') !== false
            || strpos($msg, 'unauthorized') !== false
            || strpos($msg, 'forbidden') !== false
            || strpos($msg, 'api_key') !== false
            || strpos($msg, '无权') !== false) {
            return self::ERR_CAT_AUTH;
        }
        // 参数错误
        if ($codeIn([400, 422, 4000, 4001, 1010, 1011, 1012, 1013, 1014, 1016])
            || strpos($msg, '参数') !== false
            || strpos($msg, '为空') !== false
            || strpos($msg, 'invalid') !== false
            || strpos($msg, '格式') !== false) {
            return self::ERR_CAT_PARAM;
        }
        // 临时错误
        if ($codeIn([500, 502, 503, 504, -1, 5000, 1005, 1007, 1008, 1009])
            || strpos($msg, 'timeout') !== false
            || strpos($msg, '超时') !== false
            || strpos($msg, '繁忙') !== false
            || strpos($msg, '限流') !== false
            || strpos($msg, 'rate limit') !== false
            || strpos($msg, '网络') !== false
            || strpos($msg, 'connect') !== false
            || strpos($msg, '网关') !== false) {
            return self::ERR_CAT_TEMP;
        }
        return self::ERR_CAT_UNKNOWN;
    }

    /**
     * 从数组里按候选字段顺序取第一个非空值
     */
    private static function pickFirst(array $arr, array $keys, $default = null)
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') {
                return $arr[$k];
            }
        }
        return $default;
    }

    /**
     * 构造函数
     *
     * @param array $config 配置信息
     *   - api_url: API地址（如 https://api.starloft.cn）
     *   - api_key: API Key
     *   - api_secret: API Secret
     */
    public function __construct($config)
    {
        $this->apiUrl = rtrim($config['api_url'] ?? '', '/');
        $this->apiKey = $config['api_key'] ?? '';
        $this->apiSecret = $config['api_secret'] ?? '';

        if (empty($this->apiUrl) || empty($this->apiKey) || empty($this->apiSecret)) {
            throw new \Exception('星楼网络 SMS API配置不完整，请检查插件配置');
        }
    }

    /**
     * 生成 HMAC-SHA256 签名
     *
     * 签名算法：hex(HMAC-SHA256(api_secret, 原始请求体))
     *
     * @param string $body 原始请求体（POST 为 JSON 字符串）
     * @return string 小写十六进制签名
     */
    private function generateSign($body)
    {
        return hash_hmac('sha256', $body, $this->apiSecret);
    }

    /**
     * 判断接口返回是否成功
     */
    public static function isSuccess($result)
    {
        return is_array($result) && self::classifyError($result) === self::ERR_CAT_SUCCESS;
    }

    /**
     * 发送HTTP请求
     *
     * @param string $method HTTP方法（POST/GET/PUT/DELETE）
     * @param string $endpoint API端点
     * @param array $data 请求数据（GET/DELETE 为空数组，签名串为空字符串）
     * @return array 响应数据
     */
    private function request($method, $endpoint, array $data = [])
    {
        $url = $this->apiUrl . $endpoint;

        // 序列化请求体（签名与实际发送必须完全一致）；GET/DELETE 无请求体
        $method = strtoupper($method);
        $body   = '';
        if (in_array($method, ['POST', 'PUT'], true)) {
            $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // 时间戳：Unix 秒，后端校验允许 ±5 分钟
        $timestamp = (string)time();
        // 签名：hex(HMAC-SHA256(api_secret, 原始请求体))
        $sign = $this->generateSign($body);

        $headers = [
            'Content-Type: application/json',
            'X-Api-Key: ' . $this->apiKey,
            'X-Sign: ' . $sign,
            'X-Sign-Version: hmac_sha256',
            'X-Timestamp: ' . $timestamp,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method === 'PUT' || $method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['code' => -1, 'message' => '网络请求失败: ' . $error];
        }

        $result = json_decode((string)$response, true);
        if (!is_array($result)) {
            return ['code' => -1, 'message' => '响应解析失败: ' . (string)$response];
        }

        // 双写 result_* 字段，兼容上层按原生字段判断
        $rMsg = (string)self::pickFirst($result, ['message', 'msg'], '');
        if ($rMsg !== '' && !array_key_exists('result_message', $result)) {
            $result['result_message'] = $rMsg;
        }
        $rCode = self::pickFirst($result, ['code'], null);
        if ($rCode !== null && !array_key_exists('result_code', $result)) {
            $result['result_code'] = $rCode;
        }

        return $result;
    }

    /**
     * 发送短信
     *
     * 调用 /v1/sms/send。支持模板型（template_id + template_params）与直发型（content）。
     *
     * @param array $params 参数
     *   - phone_number_set: 目标号码数组，如 ["13800138000","13900139000"]
     *   - template_id: 模板ID（模板型必填）
     *   - template_params: 模板参数数组（模板型，按模板变量顺序）
     *   - sign_name: 已审核签名
     *   - sms_type: 短信类型（verify-验证码 notify-通知；暂不支持营销）
     *   - session_context: 下行上下文（透传，可选）
     *   - content: 完整短信内容（直发型，含【签名】；模板型忽略）
     * @return array
     */
    public function send(array $params)
    {
        $payload = [];

        // 号码：数组 -> JSON 字符串（与平台契约一致）
        $phones = $params['phone_number_set'] ?? $params['phones'] ?? $params['mobile'] ?? [];
        if (is_string($phones)) {
            $phones = array_map('trim', explode(',', $phones));
        }
        if (!is_array($phones) || empty($phones)) {
            return ['code' => 400, 'message' => '短信接收号码不能为空'];
        }
        $payload['phone_number_set'] = array_values(array_filter(array_map('trim', $phones)));

        if (!empty($params['template_id'])) {
            $payload['template_id'] = (string)$params['template_id'];
        }
        if (isset($params['template_params']) && is_array($params['template_params']) && !empty($params['template_params'])) {
            $payload['template_params'] = array_values($params['template_params']);
        }
        if (!empty($params['sign_name'])) {
            $payload['sign_name'] = (string)$params['sign_name'];
        }
        if (!empty($params['sms_type'])) {
            $payload['sms_type'] = (string)$params['sms_type'];
        }
        if (!empty($params['session_context'])) {
            $payload['session_context'] = (string)$params['session_context'];
        }
        if (!empty($params['content'])) {
            $payload['content'] = (string)$params['content'];
        }

        if (empty($payload['template_id']) && empty($payload['content'])) {
            return ['code' => 400, 'message' => '模板ID与短信内容不能同时为空'];
        }

        return $this->request('POST', '/v1/sms/send', $payload);
    }

    /**
     * 创建短信模板（平台模板型）
     *
     * @param array $data ['title', 'content'(@占位), 'sms_type', 'sign_name']
     */
    public function createTemplate(array $data)
    {
        return $this->request('POST', '/v1/sms/templates', $data);
    }

    /**
     * 查询短信模板状态（支持按主键或上游模板 ID）
     */
    public function getTemplate($id)
    {
        return $this->request('GET', '/v1/sms/templates/' . rawurlencode((string)$id));
    }

    /**
     * 修改短信模板内容（重置待审核并重新报备上游）
     */
    public function updateTemplate($id, array $data)
    {
        return $this->request('PUT', '/v1/sms/templates/' . rawurlencode((string)$id), $data);
    }

    /**
     * 删除短信模板
     */
    public function deleteTemplate($id)
    {
        return $this->request('DELETE', '/v1/sms/templates/' . rawurlencode((string)$id));
    }

    /**
     * 便捷方法：按已配置的默认模板与签名发送
     *
     * @param array|string $phones 号码（数组或逗号分隔字符串）
     * @param array $templateParams 模板参数（可选）
     * @param string $templateId 模板ID（可选，缺省用 config 默认）
     * @param string $signName 签名（可选，缺省用 config 默认）
     * @param string $smsType 短信类型（可选）
     * @return array
     */
    public function sendByTemplate($phones, array $templateParams = [], $templateId = '', $signName = '', $smsType = '')
    {
        $params = [
            'phone_number_set' => $phones,
            'template_params'  => $templateParams,
            'sms_type'         => $smsType,
        ];
        if ($templateId !== '') $params['template_id'] = $templateId;
        if ($signName !== '') $params['sign_name'] = $signName;
        return $this->send($params);
    }
}
