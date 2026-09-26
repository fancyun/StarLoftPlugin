<?php

namespace StarLoft\Sdk;

/**
 * StarLoft FV 人脸核验客户端
 *
 * 接口前缀统一为 /v1/fv，API 域名 api.starloft.cn
 * 子产品：fv_auth（有源）、fv_self（无源）
 */
class FvClient
{
    /** @var Client */
    private $client;

    public function __construct(array $config)
    {
        $this->client = new Client($config);
    }

    /**
     * 发起人脸核验认证
     *
     * @param array $params
     *   - name:           string 姓名（fv_auth 必填）
     *   - id_card:        string 身份证号（fv_auth 必填）
     *   - return_url:     string 前端回跳地址（可选）
     *   - notify_url:     string 后端结果通知地址（必填）
     *   - biz_extra_data: string 业务扩展数据（可选）
     * @return array {code, message, data:{biz_no, site_url, expired_in, expired_time}}
     */
    public function startAuth(array $params)
    {
        return $this->client->request('POST', '/v1/fv/auth', $params);
    }

    /**
     * 发起无源人脸核验
     *
     * @param array $params
     *   - return_url:     string 前端回跳地址（可选）
     *   - notify_url:     string 后端结果通知地址（必填）
     *   - biz_extra_data: string 业务扩展数据（可选）
     * @return array {code, message, data:{biz_no, site_url, expired_in, expired_time}}
     */
    public function startSelf(array $params)
    {
        return $this->client->request('POST', '/v1/fv/self', $params);
    }

    /**
     * 获取认证记录的活体最佳图
     *
     * @param string $bizNo 全平台唯一流水号
     * @return array {code, message, data:{biz_no, best_img}}
     */
    public function getBestImg($bizNo)
    {
        return $this->client->request('POST', '/v1/fv/best-img', ['biz_no' => $bizNo]);
    }

    /**
     * 获取认证记录已自动保存的照片/视频（base64）
     *
     * @param string $bizNo 全平台唯一流水号
     * @return array {code, message, data:{biz_no, media}}
     */
    public function getMedia($bizNo)
    {
        return $this->client->request('POST', '/v1/fv/media', ['biz_no' => $bizNo]);
    }

    /**
     * 查询认证记录状态
     *
     * @param string $bizNo 全平台唯一流水号
     * @param int    $sync  1=立即校对一次（用户点击「我已完成扫脸」后的手动查询），0=普通轮询
     * @return array {code, message, data:{biz_no, status, result_code, result_message}}
     */
    public function queryResult($bizNo, $sync = 0)
    {
        $payload = ['biz_no' => $bizNo];
        if ((int)$sync === 1) {
            $payload['sync'] = 1;
        }
        return $this->client->request('POST', '/v1/fv/result', $payload);
    }
}
