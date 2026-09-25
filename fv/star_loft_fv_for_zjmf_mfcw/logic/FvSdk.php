<?php
namespace certification\star_loft_fv_for_zjmf_mfcw\logic;

/**
 * StarLoft 星楼网络 SDK
 *
 * 用于对接「星楼网络」平台子产品级服务（人脸核验 FV）的 SDK 类。
 * 所有请求使用 API Key + HMAC-SHA256 签名鉴权，请求头：
 *   X-Api-Key / X-Sign=hex(HMAC-SHA256(api_secret, 原始请求体)) /
 *   X-Sign-Version: hmac_sha256 / X-Timestamp
 *
 * 接口前缀统一为 /api/fv（对应 README 中的 FV 平台，域名含
 * www.starloft.cn / service.starloft.cn）。
 *
 * 平台按「子产品」对外提供服务标识(service)，如 fv_auth / fv_self 等，
 * 由 createOrder 传入。
 *
 * @author StarLoft
 * @version 2.0.0
 */
class FvSdk
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
    const ERR_CAT_SUCCESS      = 'success';      // 成功(result_code=1000 / SUCCESS)
    const ERR_CAT_NO_BALANCE   = 'no_balance';   // 商户余额/额度不足(不可重试硬错误)
    const ERR_CAT_AUTH         = 'auth';         // AppKey/签名/权限配置错误
    const ERR_CAT_PARAM        = 'param';        // 调用参数错误(字段格式/缺失等业务无关)
    const ERR_CAT_NOT_FOUND    = 'not_found';    // 订单不存在/无此任务
    const ERR_CAT_TEMP         = 'temp';         // 临时/网络/服务器错误(可继续轮询等待)
    const ERR_CAT_REJECT       = 'reject';       // 核验终态"不通过"(已计费的业务失败: 要素比对不一致/活体攻击等)
    const ERR_CAT_USER_ACTION  = 'user_action';  // 用户侧可恢复错误(未开始/进行中/浏览器权限问题等)
    const ERR_CAT_UNKNOWN      = 'unknown';

    /**
     * 根据接口返回判断错误分类
     *
     * 兼容两种返回字段:
     *   - 规范化(code/message): SDK 在 request() 末尾会把上游 result_* 双写进来
     *   - 原生(result_code/result_message): 上层直接拿接口返回值判断时也能走通
     *
     * result_code ↔ 含义映射(来源: 上游文档表):
     *   1000 SUCCESS              验证成功
     *   2000 PASS_LIVING_NOT_THE_SAME  活体过了但非同一人(计费,终态不通过)
     *   3000 NO_ID_CARD_NUMBER    无此身份证号(计费,终态不通过)
     *   3000 ID_NUMBER_NAME_NOT_MATCH  证号姓名不匹配(计费,终态不通过)
     *   3000 NO_FACE_FOUND        无检测到人脸(计费,终态不通过)
     *   3000 NO_ID_PHOTO          找不到参考照片(计费,终态不通过)
     *   3000 PHOTO_FORMAT_ERROR   参考照片格式错(计费,终态不通过)
     *   3000 DATA_SOURCE_ERROR    参考数据源错误(不计费,临时错误 -> 重试)
     *   3000 INTERNAL_ERROR       服务器内部错误(不计费,临时错误 -> 重试)
     *   4000 FAIL_LIVING_FACE_ATTACK  活体攻击/活体失败(计费,终态不通过)
     *   6000 NOT_STARTED          验证未开始(继续等)
     *   6000 PROCESSING           验证进行中(继续等)
     *   6000 FAILED               验证流程异常结束(终态失败)
     *   6000 CANCELLED            用户主动取消(终态失败)
     *   6000 TIMEOUT              验证超时(终态失败)
     *   6100 SUPPORT_ERROR        浏览器不支持 webRTC(用户侧可恢复 -> 继续等)
     *   6100 PERMISSIONS_ERROR    拒绝摄像头权限(用户侧可恢复 -> 继续等)
     *   6100 OTHER_ERROR          其他 webRTC 连接错误(用户侧可恢复 -> 继续等)
     */
    public static function classifyError($result)
    {
        if (!is_array($result)) {
            return self::ERR_CAT_UNKNOWN;
        }
        // --- 1. 统一字段: 同时兼容 SDK 规范化(code/message)与原生(result_code/result_message)
        $rawCode    = self::pickFirst($result, ['code', 'result_code']);
        $rawMessage = (string)self::pickFirst($result, ['message', 'result_message', 'msg'], '');
        // data.result_code / data.result_message(某些接口嵌套一层)
        if (is_array($result['data'] ?? null)) {
            if ($rawCode === null || $rawCode === '') {
                $rawCode = self::pickFirst($result['data'], ['code', 'result_code', 'status_code']);
            }
            if ($rawMessage === '') {
                $rawMessage = (string)self::pickFirst($result['data'], ['message', 'result_message', 'msg'], '');
            }
        }

        // --- 2. 成功判断(1000 SUCCESS / code 0 / SUCCESS 文案)
        $strCode = (string)$rawCode;
        $intCode = (int)$rawCode;
        if ($rawCode === 0 || $rawCode === '0'
            || $intCode === 1000 || $strCode === '1000'
            || in_array(strtoupper($rawMessage), ['SUCCESS', '成功', '验证成功'], true)) {
            return self::ERR_CAT_SUCCESS;
        }

        // --- 3. 原生 result_code 语义分类(优先于通用 code 分类)
        if ($rawCode !== null && $rawCode !== '') {
            // 2000 活体过了但非同一人 -> 计费,终态不通过
            if (self::matchResCode($rawCode, 2000)) {
                return self::ERR_CAT_REJECT;
            }
            // 4000 活体攻击/活体失败 -> 计费,终态不通过
            if (self::matchResCode($rawCode, 4000)) {
                return self::ERR_CAT_REJECT;
            }
            // 3000 需按 message 区分: 计费拒绝 vs 不计费临时错误
            if (self::matchResCode($rawCode, 3000)) {
                $rejectMsgs = ['NO_ID_CARD_NUMBER','ID_NUMBER_NAME_NOT_MATCH','NO_FACE_FOUND','NO_ID_PHOTO','PHOTO_FORMAT_ERROR'];
                if (in_array(strtoupper($rawMessage), $rejectMsgs, true)) {
                    return self::ERR_CAT_REJECT;
                }
                if (in_array(strtoupper($rawMessage), ['DATA_SOURCE_ERROR','INTERNAL_ERROR'], true)) {
                    return self::ERR_CAT_TEMP;
                }
            }
            // 6000 NOT_STARTED / PROCESSING -> 用户侧可恢复(继续轮询)
            if (self::matchResCode($rawCode, 6000) && in_array(strtoupper($rawMessage), ['NOT_STARTED','PROCESSING'], true)) {
                return self::ERR_CAT_USER_ACTION;
            }
            // 6000 FAILED / CANCELLED / TIMEOUT -> 核验终态失败(流程终止)
            if (self::matchResCode($rawCode, 6000) && in_array(strtoupper($rawMessage), ['FAILED','CANCELLED','TIMEOUT'], true)) {
                return self::ERR_CAT_REJECT;
            }
            // 6100 浏览器/权限/连接错误 -> 用户侧可恢复(继续轮询)
            if (self::matchResCode($rawCode, 6100)) {
                return self::ERR_CAT_USER_ACTION;
            }
        }

        // --- 4. 兼容 HTTP code / 兜底分类
        $msg = strtolower($rawMessage);
        $codeIn = function ($arr) use ($rawCode, $intCode, $strCode) {
            foreach ($arr as $v) {
                if ($rawCode === $v) return true;
                if ($intCode === (int)$v && $strCode === (string)$v) return true;
            }
            return false;
        };
        // 余额/额度不足(排除"免费次数不足")
        $isFreeTimesLimit = (strpos($rawMessage, '免费') !== false && (strpos($rawMessage, '次数') !== false || strpos($rawMessage, '不足') !== false))
                            || strpos($rawMessage, '免费次数') !== false
                            || (strpos($rawMessage, '次数') !== false && strpos($rawMessage, '不足') !== false);
        if (!$isFreeTimesLimit) {
            if ($codeIn([4003, 4004, 4029, 1004, 402, 4020, 10004])
                || strpos($msg, '余额') !== false
                || strpos($msg, 'balance') !== false
                || strpos($msg, 'insufficient') !== false
                || strpos($msg, '额度') !== false
                || (strpos($msg, '不足') !== false && strpos($msg, '次数') === false)) {
                return self::ERR_CAT_NO_BALANCE;
            }
        }
        // 鉴权/签名
        if ($codeIn([401, 403, 4010, 4011, 4013, 1001, 1002, 1003])
            || strpos($msg, 'sign') !== false
            || strpos($msg, '签名') !== false
            || strpos($msg, 'unauthorized') !== false
            || strpos($msg, 'forbidden') !== false
            || strpos($msg, 'api_key') !== false
            || strpos($msg, 'apikey') !== false
            || strpos($msg, 'api key') !== false
            || (strpos($msg, '无效的') !== false && (strpos($msg, 'key') !== false || strpos($msg, 'secret') !== false))
            || strpos($msg, '无权') !== false
            || strpos($msg, 'permission') !== false) {
            return self::ERR_CAT_AUTH;
        }
        // 参数错误
        if ($codeIn([400, 422, 4000, 4001])
            || strpos($msg, '参数') !== false
            || (strpos($msg, '身份证') !== false && (strpos($msg, '格式') !== false || strpos($msg, '无效') !== false || strpos($msg, '错误') !== false))
            || (strpos($msg, '姓名') !== false && (strpos($msg, '无效') !== false || strpos($msg, '格式') !== false || strpos($msg, '错误') !== false))
            || strpos($msg, 'id_card') !== false
            || strpos($msg, 'biz_no') !== false
            || strpos($msg, 'invalid') !== false) {
            return self::ERR_CAT_PARAM;
        }
        // 订单不存在
        if ($codeIn([404, 410, 4005])
            || strpos($msg, '不存在') !== false
            || strpos($msg, 'not found') !== false
            || strpos($msg, 'no record') !== false) {
            return self::ERR_CAT_NOT_FOUND;
        }
        // 临时错误
        if ($codeIn([500, 502, 503, 504, -1, 5000])
            || strpos($msg, 'timeout') !== false
            || strpos($msg, '超时') !== false
            || strpos($msg, '繁忙') !== false
            || strpos($msg, '限流') !== false
            || strpos($msg, 'rate limit') !== false
            || strpos($msg, '网络') !== false
            || strpos($msg, '处理中') !== false
            || strpos($msg, '排队') !== false
            || strpos($msg, 'connect') !== false
            || strpos($msg, '网关') !== false
            || strpos($msg, '维护') !== false) {
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
     * 宽松匹配 result_code(int/string)
     */
    private static function matchResCode($rawCode, $expectInt)
    {
        if ($rawCode === null || $rawCode === '') return false;
        if ($rawCode === $expectInt) return true;
        if ((string)$rawCode === (string)$expectInt) return true;
        if ((int)$rawCode === (int)$expectInt) return true;
        return false;
    }

    /**
     * 构造函数
     *
     * @param array $config 配置信息
     *   - api_url: API地址
     *   - api_key: API Key
     *   - api_secret: API Secret
     */
    public function __construct($config)
    {
        $this->apiUrl = rtrim($config['api_url'] ?? '', '/');
        $this->apiKey = $config['api_key'] ?? '';
        $this->apiSecret = $config['api_secret'] ?? '';

        if (empty($this->apiUrl) || empty($this->apiKey) || empty($this->apiSecret)) {
            throw new \Exception('星楼网络 API配置不完整，请检查插件配置');
        }
    }

    /**
     * 生成 HMAC-SHA256 签名
     *
     * 签名算法：hex(HMAC-SHA256(api_secret, 原始请求体))
     *
     * @param string $body 原始请求体（POST 为 JSON 字符串，GET 为空字符串）
     * @return string 小写十六进制签名
     */
    private function generateSign($body)
    {
        return hash_hmac('sha256', $body, $this->apiSecret);
    }

    /**
     * 发送HTTP请求
     *
     * @param string $method 请求方法 GET|POST
     * @param string $endpoint API端点
     * @param array $data 请求数据
     * @return array 响应数据
     */
    private function request($method, $endpoint, $data = [])
    {
        $url = $this->apiUrl . $endpoint;

        // 序列化请求体（签名与实际发送必须完全一致）
        $body = ($method === 'POST') ? json_encode($data) : '';

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
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            return [
                'code' => -1,
                'message' => '网络请求失败: ' . $error
            ];
        }

        $result = json_decode($response, true);

        if (!$result) {
            return [
                'code' => -1,
                'message' => 'API响应解析失败'
            ];
        }

        if ($httpCode !== 200) {
            return [
                'code'    => (int)$httpCode,
                'message' => $result['message'] ?? $result['result_message'] ?? ('HTTP错误: ' . $httpCode)
            ];
        }

        // ------ 上游返回值规范化(双写字段, 兼容新旧判断逻辑) ------
        // 把 顶层 result_code → code, 顶层 result_message → message
        // 成功时(1000)额外把 code 写成 0(兼容"code=0成功"的旧判断)
        $topCode = self::pickFirst($result, ['code', 'result_code']);
        $topMsg  = self::pickFirst($result, ['message', 'result_message', 'msg'], '');
        $intCode = (int)$topCode;

        if ($topCode !== null && !array_key_exists('code', $result)) {
            $result['code'] = ($intCode === 1000) ? 0 : $topCode;
        } elseif (array_key_exists('code', $result) && (int)$result['code'] === 1000) {
            $result['code'] = 0;
        }
        if ($topMsg !== '' && !array_key_exists('message', $result)) {
            $result['message'] = $topMsg;
        }

        // data 里也统一双写一遍 status_code(订单状态),让上层 getStatus 直接读 data.status_code/result_code 都行
        if (is_array($result['data'] ?? null)) {
            $d = &$result['data'];
            $dc = self::pickFirst($d, ['result_code', 'code', 'status_code']);
            if ($dc !== null && !array_key_exists('status_code', $d)) $d['status_code'] = $dc;
            if ($dc !== null && !array_key_exists('code', $d))        $d['code'] = $dc;
            $dm = self::pickFirst($d, ['result_message', 'message', 'msg'], '');
            if ($dm !== '' && !array_key_exists('message', $d))       $d['message'] = $dm;
        }

        return $result;
    }

    /**
     * 创建核验订单（子产品级）
     *
     * 调用 /v1/fv/auth（或 /v1/fv/self）。把选定的子产品服务标识(service)与身份要素
     * 一并提交，并把下游 notify_url / return_url 回传平台。
     *
     * @param array $params 参数
     *   - service: 平台子产品服务标识，如 fv_auth / fv_self
     *   - notify_url: 后端结果通知地址
     *   - return_url: 前端回跳地址
     *   - name / id_card: 个人实名要素
     *   - biz_extra_data: 业务扩展数据（可选）
     *   说明: biz_no 由平台随机生成下发,无需(也不应)由下游传入。
     * @return array
     */
    public function createOrder($params)
    {
        // 后端按 URL 区分子产品：fv_self → /v1/fv/self，其余（默认 fv_auth）→ /v1/fv/auth
        $endpoint = '/v1/fv/auth';
        if (($params['service'] ?? '') === 'fv_self') {
            $endpoint = '/v1/fv/self';
        }
        unset($params['service']);
        return $this->request('POST', $endpoint, $params);
    }

    /**
     * 查询/校对核验结果
     *
     * 调用 /v1/fv/result。既用于文档轮询查询，也用于「结果校对」：
     * 收到平台推送后主动调用一次，对齐上游最终状态再落地本地。
     *
     * @param array $params 参数
     *   - biz_no: 全平台唯一流水号（即 createOrder 下发的 biz_no）
     * @return array
     */
    public function queryResult($params)
    {
        return $this->request('POST', '/v1/fv/result', $params);
    }

    /**
     * 测试API连接（不再查询余额，改为请求结果查询接口验证连通与鉴权）
     *
     * @return array
     */
    public function testConnection()
    {
        return $this->queryResult(['biz_no' => '0']);
    }
}