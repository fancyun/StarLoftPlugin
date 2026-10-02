<?php

namespace StarLoft\Sdk;

/**
 * StarLoft SMS 客户端
 *
 * 接口前缀统一为 /v1/sms，API 域名 api.starloft.cn
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
     *   - sms_type:        string   verify/notify
     *   - session_context: string   下行上下文（透传，可选）
     *   - content:         string   完整短信内容（直发型，含【签名】）
     *   - notify_url:      string   回执主动推送地址（可选）
     * @return array {code, message, data:{request_id, message_sid}}
     */
    public function send(array $params)
    {
        return $this->client->request('POST', '/v1/sms/send', $params);
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
        return $this->client->request('POST', '/v1/sms/signs', $params);
    }

    /**
     * 创建短信模板
     *
     * @param array $params
     *   - title, content, sms_type, sign_name
     * @return array {code, message, data:{template:{template_id, template_status}}}
     */
    public function createTemplate(array $params)
    {
        return $this->client->request('POST', '/v1/sms/templates', $params);
    }

    /**
     * 查询短信模板状态（按平台模板主键）
     *
     * @param string|int $id 平台模板主键
     * @return array {code, message, data:{template:{template_id, template_status, msg}}}
     */
    public function getTemplate($id)
    {
        return $this->client->request('GET', '/v1/sms/templates/' . rawurlencode((string)$id));
    }

    /**
     * 修改短信模板内容（重置待审核并重新报备上游）
     *
     * @param string|int $id 平台模板主键
     * @param array $params {content}
     * @return array
     */
    public function updateTemplate($id, array $params)
    {
        return $this->client->request('PUT', '/v1/sms/templates/' . rawurlencode((string)$id), $params);
    }

    /**
     * 删除短信模板
     *
     * @param string|int $id 平台模板主键
     * @return array
     */
    public function deleteTemplate($id)
    {
        return $this->client->request('DELETE', '/v1/sms/templates/' . rawurlencode((string)$id));
    }

    /**
     * 查询短信发送回执（按上游 taskId）
     *
     * @param string $taskId 上游任务ID（发送接口返回的 request_id / message_sid）
     * @param int    $pageNo
     * @param int    $pageSize
     * @return array {code, message, data:{task_id, reports}}
     */
    public function queryReport($taskId, $pageNo = 1, $pageSize = 10)
    {
        return $this->client->request('POST', '/v1/sms/report', [
            'task_id'  => $taskId,
            'page_no'  => $pageNo,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * 查询短信上行回复
     *
     * @param string $date 回复日期 yyyyMMdd（可选，提供时先向上游拉取同步）
     * @param string $taskId
     * @param int    $pageNo
     * @param int    $pageSize
     * @return array
     */
    public function queryReplies($date = '', $taskId = '', $pageNo = 1, $pageSize = 10)
    {
        $params = ['page_no' => $pageNo, 'page_size' => $pageSize];
        if ($date !== '') {
            $params['date'] = $date;
        }
        if ($taskId !== '') {
            $params['task_id'] = $taskId;
        }
        return $this->client->request('POST', '/v1/sms/replies', $params);
    }
}
