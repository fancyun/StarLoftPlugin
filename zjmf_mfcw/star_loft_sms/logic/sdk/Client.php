<?php

namespace StarLoft\Sdk;

/**
 * StarLoft API 基础客户端
 *
 * 鉴权：API Key + HMAC-SHA256 签名
 *   X-Api-Key / X-Sign=hex(HMAC-SHA256(api_secret, 原始请求体)) /
 *   X-Sign-Version: hmac_sha256 / X-Timestamp（Unix 秒，±5 分钟）
 */
class Client
{
    /** @var string API 基础 URL */
    private $apiUrl;

    /** @var string API Key */
    private $apiKey;

    /** @var string API Secret */
    private $apiSecret;

    /** @var int 请求超时时间（秒） */
    private $timeout = 30;

    public function __construct(array $config)
    {
        $this->apiUrl    = rtrim($config['api_url'] ?? '', '/');
        $this->apiKey    = $config['api_key'] ?? '';
        $this->apiSecret = $config['api_secret'] ?? '';
        if (isset($config['timeout'])) {
            $this->timeout = (int)$config['timeout'];
        }

        if ($this->apiUrl === '' || $this->apiKey === '' || $this->apiSecret === '') {
            throw new \InvalidArgumentException('StarLoft API 配置不完整：api_url / api_key / api_secret 必填');
        }
    }

    /**
     * 发送 HTTP 请求
     *
     * @param string $method   HTTP 方法（GET/POST/PUT/DELETE）
     * @param string $endpoint 端点，如 /v1/sms/send
     * @param array  $data     POST/PUT 请求体；GET/DELETE 签名串为空字符串
     * @return array 解码后的响应数组
     */
    public function request($method, $endpoint, array $data = [])
    {
        $url    = $this->apiUrl . $endpoint;
        $method = strtoupper($method);

        $body = '';
        if (in_array($method, ['POST', 'PUT'], true)) {
            $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $timestamp = (string)time();
        $sign      = hash_hmac('sha256', $body, $this->apiSecret);

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
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['code' => -1, 'message' => '网络请求失败: ' . $error];
        }

        $result = json_decode((string)$response, true);
        if (!is_array($result)) {
            return ['code' => -1, 'message' => '响应解析失败: ' . (string)$response];
        }

        return $result;
    }
}
