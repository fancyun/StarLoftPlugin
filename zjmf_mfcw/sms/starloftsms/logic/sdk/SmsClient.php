<?php

namespace StarLoft\Sdk;

/**
 * StarLoft SMS 客户端
 *
 * 接口前缀统一为 /v2/sms（创蓝 253 通道），API 域名 api.starloft.cn
 */
class SmsClient
{
    /** @var Client */
    private $client;

    public function __construct(array $config)
    {
        $this->client = new Client($config);
    }

    /**
     * 发送短信
     *
     * @param array $params
     *   - phone_number_set: string[] 目标号码
     *   - template_id:     string   模板ID（模板型必填）
     *   - template_params: string[] 模板参数（按模板变量顺序）
     *   - sign_name:       string   已审核签名
     *   - session_context: string   下行上下文（透传，可选）
     *   - content:         string   完整短信内容（直发型，含【签名】）
     *   - notify_url:      string   回执主动推送地址（可选）
     * 注：短信类别由平台按所发模板自动匹配（模板类别在平台审核时判定），请求无需也无法指定
     * @return array {code, message, data:{biz_no, request_id}}
     */
    public function send(array $params)
    {
        return $this->client->request('POST', '/v2/sms/send', $params);
    }

    /**
     * 创建短信签名（资质材料以 URL 传入）
     *
     * @param array $params
     *   - sign_name, label, credit_code_url, id_card_front, id_card_back,
     *     company, legal_person, credit_code, credit_user_name, id_card,
     *     phone, sx_commits, auth_letter, screenshot
     * @return array {code, message, data:{sign_id, up_sign_id, status}}
     */
    public function createSign(array $params)
    {
        return $this->client->request('POST', '/v2/sms/signs', $params);
    }

    /**
     * 创建短信模板
     *
     * 模板类别（验证码/通知/营销）由平台在审核环节判定填写，不在请求参数中传递
     *
     * @param array $params
     *   - title, content（变量占位统一写作 {s}）, sign_name
     *   - variables: array 变量属性，按 {s} 出现顺序：[{type, length}]；type 取
     *     字符型 / 整数型 / 小数型 / 字符数据混合型，length 为长度（字，正整数）；条数须等于 {s} 个数
     * @return array {code, message, data:{template:{template_id, template_status}}}
     */
    public function createTemplate(array $params)
    {
        return $this->client->request('POST', '/v2/sms/templates', $params);
    }

    /**
     * 查询短信模板状态（按平台模板主键）
     *
     * @param string|int $id 平台模板主键
     * @return array {code, message, data:{template:{template_id, template_status, msg}}}
     */
    public function getTemplate($id)
    {
        return $this->client->request('GET', '/v2/sms/templates/' . rawurlencode((string)$id));
    }

    /**
     * 修改短信模板内容（重置待审核，由平台在上游人工报备）
     *
     * @param string|int $id 平台模板主键
     * @param array $params {content, variables}（content 变量占位用 {s}，variables 规则同创建）
     * @return array
     */
    public function updateTemplate($id, array $params)
    {
        return $this->client->request('PUT', '/v2/sms/templates/' . rawurlencode((string)$id), $params);
    }

    /**
     * 删除短信模板
     *
     * @param string|int $id 平台模板主键
     * @return array
     */
    public function deleteTemplate($id)
    {
        return $this->client->request('DELETE', '/v2/sms/templates/' . rawurlencode((string)$id));
    }

    /**
     * 查询短信发送回执（创蓝为推送模式，平台只回显本地已落库的批次状态）
     *
     * @param string $bizNo 平台业务号（发送接口返回的 biz_no，推荐）
     * @param string $taskId 上游消息号（创蓝 msgid，兼容保留）
     * @return array {code, message, data:{biz_no, message_sid, status, resp_code, resp_time, reports}}
     */
    public function queryReport($bizNo, $taskId = '')
    {
        $params = ['biz_no' => (string)$bizNo];
        if ($taskId !== '') {
            $params['task_id'] = (string)$taskId;
        }
        return $this->client->request('POST', '/v2/sms/report', $params);
    }

    /**
     * 查询短信上行回复（创蓝为推送模式，只查平台本地已落库记录）
     *
     * @param string $taskId 上游消息号（创蓝 msgid，可选，留空查本账号全部）
     * @param int    $pageNo
     * @param int    $pageSize
     * @return array {code, message, data:{total, replies}}
     */
    public function queryReplies($taskId = '', $pageNo = 1, $pageSize = 10)
    {
        $params = ['page_no' => $pageNo, 'page_size' => $pageSize];
        if ($taskId !== '') {
            $params['task_id'] = (string)$taskId;
        }
        return $this->client->request('POST', '/v2/sms/replies', $params);
    }
}
