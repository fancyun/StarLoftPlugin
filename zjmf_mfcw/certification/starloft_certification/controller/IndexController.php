<?php
namespace certification\starloft_certification\controller;

use certification\starloft_certification\StarloftCertificationPlugin;

/**
 * StarLoft 人脸核验(FV)插件 - 外部访问控制器（智简魔方财务版）
 *
 * 财务版实名认证插件的外部访问入口固定放在插件根目录 controller/ 下，
 * 地址形如 /certification/starloft_certification/{方法}：
 *   - 认证完成回跳: /certification/starloft_certification/result
 *   - 异步通知:     /certification/starloft_certification/callback
 *   - 状态查询:     /certification/starloft_certification/getStatus
 *
 * 不继承框架基类：不同魔方版本的基类命名空间不一致，继承会让缺类变成致命错误把页面打成 500。
 */
class IndexController
{
    /**
     * 认证完成回跳页（用 return_url 指向本页时展示核验结果）
     */
    public function result()
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo (new StarloftCertificationPlugin())->result([]);
    }

    /**
     * 平台异步通知入口（核验结果推送）
     */
    public function callback()
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo (new StarloftCertificationPlugin())->callback([]);
    }

    /**
     * 核验状态查询（认证页轮询）
     */
    public function getStatus()
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $certifyId = trim((string)($_POST['certif_id'] ?? $_GET['certif_id'] ?? $_POST['certify_id'] ?? $_GET['certify_id'] ?? ''));
        if ($certifyId === '') {
            echo json_encode(['status' => 0, 'msg' => '缺少任务流水号']);
            return;
        }
        try {
            echo json_encode((new StarloftCertificationPlugin())->getStatus(['certify_id' => $certifyId]));
        } catch (\Throwable $e) {
            echo json_encode(['status' => 4, 'msg' => '查询异常: ' . $e->getMessage()]);
        }
    }
}
