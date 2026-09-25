<?php
namespace certification\star_loft_fv_for_zjmf_v10\controller;

use certification\star_loft_fv_for_zjmf_v10\StarLoftFvForZjmfV10;

/**
 * StarLoft 人脸核验(FV)插件 - 外部回调控制器（智简魔方业务系统 v10 · 人脸识别型 _fv）
 *
 * 按 v10 实名认证接口规范，插件根目录下创建 controller 目录用于外部访问（异步/同步回调）。
 * 访问地址：
 *   - 异步通知:   {域名}/certification/star_loft_fv_for_zjmf_v10/index/notifyHandle
 *   - 认证完成回跳: {域名}/certification/star_loft_fv_for_zjmf_v10/index/result
 *   - 状态查询(AJAX): {域名}/certification/star_loft_fv_for_zjmf_v10/index/status
 *
 * 收到平台异步推送后，先做结果校对（调用 /api/fv/result 对齐上游），再落地本地。
 *
 * @author StarLoft
 * @version 2.0.0
 */
class IndexController
{
    /**
     * 异步通知处理（StarLoft FV 平台核验结果通知）
     *
     * 插件校验签名后，先调用结果校对接口对齐上游，再落地本地状态。
     */
    public function notifyHandle()
    {
        header('Content-Type: application/json; charset=utf-8');

        $body = file_get_contents('php://input');
        $data = json_decode((string)$body, true);
        if (!is_array($data) || empty($data)) {
            $data = $_POST;
        }

        $sign = (string)($data['sign'] ?? '');
        $plugin = new StarLoftFvForZjmfV10();
        if (!$plugin->verifyNotifySign($data, $sign)) {
            echo json_encode(['code' => 401, 'message' => 'signature verification failed']);
            return;
        }

        $bizNo = trim((string)($data['biz_no'] ?? ''));
        if ($bizNo === '') {
            echo json_encode(['code' => 400, 'message' => '缺少 biz_no']);
            return;
        }

        // 主动调用平台「结果校对」接口一次，对齐上游最终状态后再落地本地
        $reconciled = $plugin->reconcile($bizNo);
        $localStatus = (int)($reconciled['status'] ?? 0);
        $resultMsg   = (string)($reconciled['msg'] ?? '');

        if (($localStatus === 1 || $localStatus === 2) && $resultMsg !== '') {
            $updated = $this->updateByCertifyId($bizNo, $localStatus, $resultMsg);
            echo json_encode($updated
                ? ['code' => 0, 'message' => 'success']
                : ['code' => 404, 'message' => '未找到对应实名记录']);
            return;
        }

        $updated = $this->updateByCertifyId($bizNo, 4, $resultMsg);
        echo json_encode($updated
            ? ['code' => 0, 'message' => 'success']
            : ['code' => 404, 'message' => '未找到对应实名记录']);
    }

    /**
     * 认证完成回跳页（用户在上游完成核验后返回）
     */
    public function result()
    {
        header('Content-Type: text/html; charset=utf-8');
        $certifyId = trim((string)($_GET['certify_id'] ?? $_POST['certify_id'] ?? ''));

        $statusHtml = '<p>正在查询核验结果...</p>';
        if ($certifyId !== '') {
            try {
                $plugin = new StarLoftFvForZjmfV10();
                $res = $plugin->getStatus(['certify_id' => $certifyId]);
                $s = (int)($res['status'] ?? 0);
                $msg = htmlspecialchars((string)($res['msg'] ?? ''), ENT_QUOTES, 'UTF-8');
                if ($s === 1) {
                    $statusHtml = '<div style="color:#19be6b;font-weight:bold;">核验已通过，请前往会员中心查看。</div>';
                } elseif ($s === 2) {
                    $statusHtml = '<div style="color:#f56c6c;font-weight:bold;">核验未通过：' . $msg . '</div>';
                } else {
                    $statusHtml = '<div style="color:#e6a23c;">核验处理中：' . $msg . '</div>';
                }
            } catch (\Throwable $e) {
                $statusHtml = '<div style="color:#f56c6c;">查询异常：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
            }
        }

        $certifyIdSafe = htmlspecialchars($certifyId, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>身份核验结果</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; background:#f5f7fa; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
  .card { background:#fff; border-radius:8px; padding:40px 32px; width:90%; max-width:420px; text-align:center; box-shadow:0 2px 12px rgba(0,0,0,.06); }
  .tip { color:#909399; font-size:13px; margin-top:8px; }
  a { display:inline-block; margin-top:24px; color:#fff; background:#409eff; padding:10px 28px; border-radius:4px; text-decoration:none; }
</style>
</head>
<body>
<div class="card">
  <h3>身份核验</h3>
  {$statusHtml}
  <div class="tip">任务流水号：{$certifyIdSafe}</div>
  <a href="/" onclick="history.back();return false;">返回会员中心</a>
</div>
</body>
</html>
HTML;
    }

    /**
     * 状态查询（AJAX，供认证页轮询使用）
     */
    public function status()
    {
        header('Content-Type: application/json; charset=utf-8');
        $certifyId = trim((string)($_POST['certif_id'] ?? $_GET['certif_id'] ?? $_POST['certify_id'] ?? $_GET['certify_id'] ?? ''));

        if ($certifyId === '') {
            echo json_encode(['status' => 0, 'msg' => '缺少任务流水号']);
            return;
        }

        try {
            $plugin = new StarLoftFvForZjmfV10();
            $res = $plugin->getStatus(['certify_id' => $certifyId]);
            echo json_encode($res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 4, 'msg' => '查询异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 按 certify_id（全平台唯一流水号 biz_no）定位并更新本机实名记录状态
     */
    protected function updateByCertifyId($certifyId, $status, $resultMsg = '')
    {
        try {
            if (!class_exists('think\Db')) return false;

            $tables = [
                'host_certification_person',
                'host_certification',
                'certification_person',
                'certification',
                'im_host_user_certification',
                'host_user_certification',
                'im_certification_personal',
                'certification_personal',
            ];
            foreach ($tables as $tbl) {
                try {
                    $rec = \think\Db::name($tbl)
                        ->where('certify_id', $certifyId)
                        ->find();
                    if (!$rec) continue;

                    \think\Db::name($tbl)->where('id', $rec['id'])->update([
                        'status'      => $status,
                        'auth_fail'   => $resultMsg,
                        'update_time' => date('Y-m-d H:i:s'),
                    ]);
                    return true;
                } catch (\Throwable $_) {}
            }

            try {
                $like = \think\Db::query("SHOW TABLES LIKE '%certif%'");
                if (!empty($like)) {
                    foreach ($like as $r) {
                        $tblName = array_values($r)[0] ?? '';
                        if ($tblName === '') continue;
                        try {
                            $rec = \think\Db::name($tblName)->where('certify_id', $certifyId)->find();
                            if (!$rec) continue;
                            \think\Db::name($tblName)->where('id', $rec['id'])->update([
                                'status'      => $status,
                                'auth_fail'   => $resultMsg,
                                'update_time' => date('Y-m-d H:i:s'),
                            ]);
                            return true;
                        } catch (\Throwable $_) {}
                    }
                }
            } catch (\Throwable $_) {}
        } catch (\Throwable $_) {}
        return false;
    }
}