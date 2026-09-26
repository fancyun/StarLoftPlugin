<?php
namespace certification\star_loft_fv_for_zjmf_v10\logic;

require_once __DIR__ . '/sdk/Client.php';
require_once __DIR__ . '/sdk/FvClient.php';

use StarLoft\Sdk\FvClient;

/**
 * StarLoft 星楼网络 FV 插件适配层
 *
 * HTTP 通信与 HMAC-SHA256 签名由 StarLoft PHP SDK（logic/sdk/）实现，
 * 本类保留插件侧错误分类与结果规范化逻辑。
 *
 * @author StarLoft
 * @version 2.1.0
 */
class FvSdk
{
    /** @var FvClient */
    private $client;

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
     * result_code ↔ 含义映射(来源: 上游文档表):
     *   1000 SUCCESS / 2000 活体过但非同一人 / 3000 要素类(需按 message 细分) /
     *   4000 活体攻击 / 6000 流程类 / 6100 浏览器权限类
     */
    public static function classifyError($result)
    {
        if (!is_array($result)) {
            return self::ERR_CAT_UNKNOWN;
        }
        $rawCode    = self::pickFirst($result, ['code', 'result_code']);
        $rawMessage = (string)self::pickFirst($result, ['message', 'result_message', 'msg'], '');
        if (is_array($result['data'] ?? null)) {
            if ($rawCode === null || $rawCode === '') {
                $rawCode = self::pickFirst($result['data'], ['code', 'result_code', 'status_code']);
            }
            if ($rawMessage === '') {
                $rawMessage = (string)self::pickFirst($result['data'], ['message', 'result_message', 'msg'], '');
            }
        }

        $strCode = (string)$rawCode;
        $intCode = (int)$rawCode;
        if ($rawCode === 0 || $rawCode === '0'
            || $intCode === 1000 || $strCode === '1000'
            || in_array(strtoupper($rawMessage), ['SUCCESS', '成功', '验证成功'], true)) {
            return self::ERR_CAT_SUCCESS;
        }

        if ($rawCode !== null && $rawCode !== '') {
            if (self::matchResCode($rawCode, 2000)) {
                return self::ERR_CAT_REJECT;
            }
            if (self::matchResCode($rawCode, 4000)) {
                return self::ERR_CAT_REJECT;
            }
            if (self::matchResCode($rawCode, 3000)) {
                $rejectMsgs = ['NO_ID_CARD_NUMBER','ID_NUMBER_NAME_NOT_MATCH','NO_FACE_FOUND','NO_ID_PHOTO','PHOTO_FORMAT_ERROR'];
                if (in_array(strtoupper($rawMessage), $rejectMsgs, true)) {
                    return self::ERR_CAT_REJECT;
                }
                if (in_array(strtoupper($rawMessage), ['DATA_SOURCE_ERROR','INTERNAL_ERROR'], true)) {
                    return self::ERR_CAT_TEMP;
                }
            }
            if (self::matchResCode($rawCode, 6000) && in_array(strtoupper($rawMessage), ['NOT_STARTED','PROCESSING'], true)) {
                return self::ERR_CAT_USER_ACTION;
            }
            if (self::matchResCode($rawCode, 6000) && in_array(strtoupper($rawMessage), ['FAILED','CANCELLED','TIMEOUT'], true)) {
                return self::ERR_CAT_REJECT;
            }
            if (self::matchResCode($rawCode, 6100)) {
                return self::ERR_CAT_USER_ACTION;
            }
        }

        $msg = strtolower($rawMessage);
        $codeIn = function ($arr) use ($rawCode, $intCode, $strCode) {
            foreach ($arr as $v) {
                if ($rawCode === $v) return true;
                if ($intCode === (int)$v && $strCode === (string)$v) return true;
            }
            return false;
        };
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
        if ($codeIn([400, 422, 4000, 4001])
            || strpos($msg, '参数') !== false
            || (strpos($msg, '身份证') !== false && (strpos($msg, '格式') !== false || strpos($msg, '无效') !== false || strpos($msg, '错误') !== false))
            || (strpos($msg, '姓名') !== false && (strpos($msg, '无效') !== false || strpos($msg, '格式') !== false || strpos($msg, '错误') !== false))
            || strpos($msg, 'id_card') !== false
            || strpos($msg, 'biz_no') !== false
            || strpos($msg, 'invalid') !== false) {
            return self::ERR_CAT_PARAM;
        }
        if ($codeIn([404, 410, 4005])
            || strpos($msg, '不存在') !== false
            || strpos($msg, 'not found') !== false
            || strpos($msg, 'no record') !== false) {
            return self::ERR_CAT_NOT_FOUND;
        }
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
        try {
            $this->client = new FvClient($config);
        } catch (\InvalidArgumentException $e) {
            throw new \Exception('星楼网络 API配置不完整，请检查插件配置');
        }
    }

    /**
     * 上游返回值规范化(双写字段, 兼容新旧判断逻辑)
     */
    private static function normalize($result)
    {
        if (!is_array($result)) {
            return $result;
        }
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
     * @param array $params 参数
     *   - service: 平台子产品服务标识，如 fv_auth / fv_self
     *   - notify_url: 后端结果通知地址
     *   - return_url: 前端回跳地址
     *   - name / id_card: 个人实名要素
     *   - biz_extra_data: 业务扩展数据（可选）
     * @return array
     */
    public function createOrder($params)
    {
        $service = $params['service'] ?? '';
        unset($params['service']);
        $result = ($service === 'fv_self')
            ? $this->client->startSelf($params)
            : $this->client->startAuth($params);
        return self::normalize($result);
    }

    /**
     * 查询/校对核验结果
     *
     * @param array $params
     *   - biz_no: 全平台唯一流水号（即 createOrder 下发的 biz_no）
     *   - sync:   1=立即校对一次（用户点击「我已完成扫脸」后的手动查询），0=普通轮询
     * @return array
     */
    public function queryResult($params)
    {
        return self::normalize($this->client->queryResult(
            $params['biz_no'] ?? '',
            (int)($params['sync'] ?? 0)
        ));
    }

    /**
     * 测试API连接（请求结果查询接口验证连通与鉴权）
     *
     * @return array
     */
    public function testConnection()
    {
        return $this->queryResult(['biz_no' => '0']);
    }
}
