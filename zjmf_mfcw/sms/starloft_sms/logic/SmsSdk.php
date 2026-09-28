<?php
namespace sms\starloft_sms\logic;

require_once __DIR__ . '/sdk/Client.php';
require_once __DIR__ . '/sdk/SmsClient.php';

use StarLoft\Sdk\SmsClient;

/**
 * StarLoft 星楼网络 SMS 插件适配层
 *
 * HTTP 通信与 HMAC-SHA256 签名由 StarLoft PHP SDK（logic/sdk/）实现，
 * 本类保留插件侧错误分类与便捷发送逻辑。
 *
 * @author StarLoft
 * @version 3.1.0
 */
class SmsSdk
{
    /** @var SmsClient */
    private $client;

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
        try {
            $this->client = new SmsClient($config);
        } catch (\InvalidArgumentException $e) {
            throw new \Exception('星楼网络 SMS API配置不完整，请检查插件配置');
        }
    }

    /**
     * 判断接口返回是否成功
     */
    public static function isSuccess($result)
    {
        return is_array($result) && self::classifyError($result) === self::ERR_CAT_SUCCESS;
    }

    /**
     * 发送短信
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

        // 号码：数组 -> 过滤空值（与平台契约一致）
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

        return $this->client->send($payload);
    }

    /**
     * 创建短信模板（平台模板型）
     *
     * @param array $data ['title', 'content'(@占位), 'sms_type', 'sign_name']
     */
    public function createTemplate(array $data)
    {
        return $this->client->createTemplate($data);
    }

    /**
     * 查询短信模板状态（支持按主键或上游模板 ID）
     */
    public function getTemplate($id)
    {
        return $this->client->getTemplate($id);
    }

    /**
     * 修改短信模板内容（重置待审核并重新报备上游）
     */
    public function updateTemplate($id, array $data)
    {
        return $this->client->updateTemplate($id, $data);
    }

    /**
     * 删除短信模板
     */
    public function deleteTemplate($id)
    {
        return $this->client->deleteTemplate($id);
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
