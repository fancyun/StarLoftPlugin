<?php
namespace certification\star_loft_fv_for_zjmf_mfcw;

use app\admin\lib\Plugin;
use certification\star_loft_fv_for_zjmf_mfcw\logic\FvSdk;

/**
 * StarLoft 人脸核验(FV)插件（智简魔方财务版 · 人脸识别型 _fv）
 *
 * 对接「星楼网络」人脸核验(FV)服务，面向智简魔方(IDC)用户提供「有源人脸识别」，
 * 子产品服务标识(service)为 fv_auth（身份证参考照片比对，不保留照片复用无源）。
 *
 * 支持：
 *   - 个人实名：姓名+身份证号 + 人脸核验（fv_auth）
 *   （企业实名已停用：后端 FV API 仅支持个人实名，不支持企业四要素）
 *
 * 收到平台结果后，插件主动调用 /v1/fv/result 做一次「结果校对」，对齐上游后再落地本地。
 *
 * @author StarLoft
 * @version 2.0.0
 */
class StarLoftFvForZjmfMfcwPlugin extends Plugin
{
    /**
     * 插件基本信息
     */
    public $info = [
        'name'        => 'StarLoftFvForZjmfMfcw',
        'title'       => 'StarLoft FV人脸核验认证',
        'description' => 'StarLoft FV 人脸核验认证（有源人脸）— 智简魔方财务版',
        'status'      => 1,
        'author'      => 'StarLoft',
        'version'     => '2.0.0',
        'help_url'    => 'https://docs.starloft.cn/fv/plugin/star_loft_fv_for_zjmf_mfcw'
    ];

    /** 轮询最大次数 */
    const MAX_POLL_COUNT = 500;

    /** 查询出错时的轮询上限 */
    const MAX_ERROR_COUNT = 60;

    /** NOT_FOUND 连续多少次后判终态失败 */
    const MAX_NOT_FOUND = 5;

    /**
     * 人脸核验使用的子产品服务标识（默认 fv_auth，可覆盖）
     */
    protected function buildService($config)
    {
        $svc = trim((string)($config['service'] ?? ''));
        if ($svc !== '') {
            return $svc;
        }
        return 'fv_auth';
    }

    /**
     * 前台自定义字段：不声明任何字段（返回空数组）。
     *
     * 智简魔方财务版的人脸实名表单已自带「真实姓名 / 证件类型 / 身份证类型 / 证件号码」，
     * 提交键即 name / card / card_type（见 personal() 的取值）。
     * 此处若再声明「姓名 / 身份证号码」，宿主会在表单上追加两个字段，
     * 出现「姓名 + 真实姓名」「身份证号码 + 证件号码」两组重复输入框。
     */
    public function collectionInfo($type = null)
    {
        return [];
    }

    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    /**
     * 个人实名认证入口（有源人脸）
     */
    public function personal($certifi)
    {
        try {
            $config = $this->getConfig();

            $sdk = new FvSdk($config);

            $name   = trim((string)($certifi['name']   ?? (is_array($certifi['certifi'] ?? null) ? ($certifi['certifi']['name'] ?? '') : '')));
            $idCard = trim((string)($certifi['card']   ?? (is_array($certifi['certifi'] ?? null) ? ($certifi['certifi']['card'] ?? '') : '')));
            if ($name === '' || $idCard === '') {
                return $this->failHtml('姓名和身份证号不能为空,请返回重新填写');
            }

            return $this->createFvOrder($certifi, $config, $sdk, 'personal', [
                'name'    => $name,
                'id_card' => $idCard,
            ]);
        } catch (\Exception $e) {
            $errorMsg = '系统错误: ' . $e->getMessage();
            $this->writePersonalStatus([
                'status'    => 4,
                'auth_fail' => $errorMsg,
                'certify_id'=> '',
                'notes'     => 'Exception: ' . $e->getMessage(),
            ]);
            return $this->failHtml($errorMsg);
        }
    }

    /**
     * 企业实名认证入口（已停用：后端 FV API 仅支持个人实名，不支持企业四要素）
     */
    public function company($certifi)
    {
        return $this->failHtml('企业实名暂不支持，请使用个人实名');
    }

    /**
     * 创建人脸核验（fv_auth）订单并跳转认证
     */
    protected function createFvOrder($certifi, $config, $sdk, $mode, array $identity)
    {
        $uid    = $this->resolveCurrentUid($certifi);
        $domain = $this->resolveDomain();

        $orderParams = $identity;
        $orderParams['service']        = $this->buildService($config);
        $orderParams['return_url']     = $domain . '/certification/star_loft_fv_for_zjmf_mfcw/result?uid=' . $uid;
        $orderParams['biz_extra_data'] = json_encode(['uid' => $uid]);
        if (!$this->skipNotify($config)) {
            $orderParams['notify_url'] = $domain . '/certification/star_loft_fv_for_zjmf_mfcw/callback?uid=' . $uid;
        }

        $result = $sdk->createOrder($orderParams);
        $cat    = FvSdk::classifyError($result);

        if ($cat !== FvSdk::ERR_CAT_SUCCESS) {
            $msg = (string)($result['message'] ?? '人脸核验接口请求失败,请稍后再试');
            $displayMsg = $this->describeStartError($cat, $msg);
            $failHard = in_array($cat, [
                FvSdk::ERR_CAT_NO_BALANCE, FvSdk::ERR_CAT_AUTH, FvSdk::ERR_CAT_PARAM,
            ], true);
            $this->writeStatusByMode($mode, [
                'status'    => $failHard ? 2 : 4,
                'auth_fail' => $displayMsg,
                'certify_id'=> '',
                'notes'     => "createOrder错误[{$cat}]: {$msg}\n时间: " . date('Y-m-d H:i:s'),
            ]);
            return $this->failHtml($displayMsg);
        }

        $bizNo   = (string)($result['data']['biz_no'] ?? '');
        $authUrl = (string)($result['data']['auth_url'] ?? '');

        $this->writeStatusByMode($mode, [
            'status'     => 4,
            'auth_fail'  => '',
            'certify_id' => $bizNo,
            'notes'      => "FV平台流水号: {$bizNo}\n创建时间: " . date('Y-m-d H:i:s'),
        ]);

        if ($authUrl === '') {
            return $this->continuePollHtml($bizNo, '人脸核验请求已提交,正在等待平台返回结果...');
        }
        return $this->buildAuthHtml($authUrl);
    }

    /**
     * 查询认证状态(前端轮询入口)
     *
     * 每次查询调用 /api/fv/result，即为对上游的「结果校对」；校验出终态后才落地本地。
     */
    public function getStatus($certifi)
    {
        $certifyId = $this->resolveCertifyId($certifi);
        if ($certifyId === '') {
            return ['status' => 0, 'msg' => '尚未发起身份核验'];
        }

        try {
            $config = $this->getConfig();
            $sdk    = new FvSdk($config);
            $result = $sdk->queryResult(['biz_no' => $certifyId]);
            $cat    = FvSdk::classifyError($result);

            if ($cat === FvSdk::ERR_CAT_SUCCESS) {
                $orderData    = is_array($result['data'] ?? null) ? $result['data'] : [];
                $orderCode    = (int)($orderData['result_code'] ?? $orderData['status_code'] ?? $orderData['code'] ?? 0);
                $orderMessage = (string)($orderData['result_message'] ?? $orderData['message'] ?? '');

                if ($orderCode === 1000 || $orderMessage === 'SUCCESS') {
                    $this->writePersonalStatus([
                        'status'     => 1,
                        'auth_fail'  => '',
                        'certify_id' => $certifyId,
                    ]);
                    return ['status' => 1, 'msg' => '身份核验通过'];
                }

                $rejectMsgs = [
                    'PASS_LIVING_NOT_THE_SAME',
                    'NO_ID_CARD_NUMBER','ID_NUMBER_NAME_NOT_MATCH','NO_FACE_FOUND','NO_ID_PHOTO','PHOTO_FORMAT_ERROR',
                    'FAIL_LIVING_FACE_ATTACK',
                    'FAILED','CANCELLED','TIMEOUT',
                ];
                if (in_array($orderCode, [2000, 4000], true)
                    || ($orderCode === 3000 && in_array($orderMessage, $rejectMsgs, true))
                    || ($orderCode === 6000 && in_array($orderMessage, ['FAILED','CANCELLED','TIMEOUT'], true))) {
                    $failMsg = $this->translateResultMsg($orderMessage) ?: ($orderData['result_message'] ?? '身份核验未通过');
                    $this->writePersonalStatus([
                        'status'     => 2,
                        'auth_fail'  => $failMsg,
                        'certify_id' => $certifyId,
                    ]);
                    return ['status' => 2, 'msg' => $failMsg];
                }

                if ($orderCode === 6000 && in_array($orderMessage, ['NOT_STARTED','PROCESSING'], true)) {
                    $tipMap = ['NOT_STARTED' => '等待您打开认证页面完成操作...', 'PROCESSING' => '认证处理中,请稍候...'];
                    return $this->trackPollAndReturn($certifyId, 4, $tipMap[$orderMessage] ?? '认证处理中,请稍候...');
                }

                if ($orderCode === 6100) {
                    $tipMap = [
                        'SUPPORT_ERROR'     => '当前浏览器不支持人脸核验(需要支持 webRTC),请改用 Chrome/Edge 最新版后重试。',
                        'PERMISSIONS_ERROR' => '摄像头权限被拒绝,请允许浏览器使用摄像头后刷新页面重新进入。',
                        'OTHER_ERROR'       => 'webRTC 连接异常,请检查网络/摄像头后刷新重试。',
                    ];
                    return $this->trackPollAndReturn($certifyId, 4, $tipMap[$orderMessage] ?? '认证遇到问题,请检查浏览器摄像头权限后重试。');
                }

                if ($orderCode === 3000 && in_array($orderMessage, ['DATA_SOURCE_ERROR','INTERNAL_ERROR'], true)) {
                    return $this->trackPollAndReturn($certifyId, 4, '服务端临时异常,持续重试中...(' . $orderMessage . ')', 'err', self::MAX_ERROR_COUNT);
                }

                return $this->trackPollAndReturn($certifyId, 4, '认证处理中,请稍候...(order_code=' . $orderCode . ')');
            }

            $msg = (string)($result['message'] ?? $result['result_message'] ?? '查询失败');

            if ($cat === FvSdk::ERR_CAT_REJECT) {
                $failMsg = $this->translateResultMsg((string)($result['result_message'] ?? '')) ?: $msg;
                $this->writePersonalStatus([
                    'status'     => 2,
                    'auth_fail'  => '身份核验未通过: ' . $failMsg,
                    'certify_id' => $certifyId,
                ]);
                return ['status' => 2, 'msg' => '身份核验未通过: ' . $failMsg];
            }

            if ($cat === FvSdk::ERR_CAT_USER_ACTION) {
                return $this->trackPollAndReturn($certifyId, 4, $msg ?: '认证处理中,请稍候...');
            }

            if ($cat === FvSdk::ERR_CAT_NO_BALANCE) {
                $this->writePersonalStatus([
                    'status'     => 2,
                    'auth_fail'  => 'FV平台商户余额/额度不足,请联系管理员充值后重试。(' . $msg . ')',
                    'certify_id' => $certifyId,
                ]);
                return ['status' => 2, 'msg' => 'FV平台商户余额/额度不足,请联系管理员充值后重试。'];
            }
            if ($cat === FvSdk::ERR_CAT_AUTH) {
                $this->writePersonalStatus([
                    'status'     => 2,
                    'auth_fail'  => 'FV平台鉴权失败,请联系管理员检查配置。(' . $msg . ')',
                    'certify_id' => $certifyId,
                ]);
                return ['status' => 2, 'msg' => 'FV平台鉴权失败,请联系管理员检查配置。'];
            }
            if ($cat === FvSdk::ERR_CAT_PARAM) {
                $this->writePersonalStatus([
                    'status'     => 2,
                    'auth_fail'  => '实名参数错误: ' . $msg,
                    'certify_id' => $certifyId,
                ]);
                return ['status' => 2, 'msg' => '实名参数错误: ' . $msg];
            }

            if ($cat === FvSdk::ERR_CAT_NOT_FOUND) {
                $cnt = $this->incPollCounter($certifyId, 'notfound');
                if ($cnt >= self::MAX_NOT_FOUND) {
                    $this->writePersonalStatus([
                        'status'     => 2,
                        'auth_fail'  => '查询不到核验任务(已超过重试次数),请稍后重新发起。(' . $msg . ')',
                        'certify_id' => $certifyId,
                    ]);
                    return ['status' => 2, 'msg' => '查询不到核验任务,请稍后重新发起。'];
                }
                return ['status' => 4, 'msg' => '任务查询中,请稍候...(未找到,剩余重试 ' . (self::MAX_NOT_FOUND - $cnt) . ')'];
            }

            return $this->trackPollAndReturn($certifyId, 4, '查询中,请稍候...(' . $msg . ')', 'err', self::MAX_ERROR_COUNT);
        } catch (\Exception $e) {
            return $this->trackPollAndReturn($certifyId, 4, '查询中,请稍候...(异常:' . $e->getMessage() . ')', 'err', self::MAX_ERROR_COUNT);
        }
    }

    protected function skipNotify($config)
    {
        return (int)($config['skip_notify'] ?? 0) === 1;
    }

    protected function describeStartError($cat, $msg)
    {
        switch ($cat) {
            case FvSdk::ERR_CAT_NO_BALANCE:
                return 'FV平台商户余额/额度不足,请联系管理员充值后重试。(返回:' . $msg . ')';
            case FvSdk::ERR_CAT_AUTH:
                return 'FV平台鉴权失败(AppKey/签名配置错误),请联系管理员检查插件配置。(返回:' . $msg . ')';
            case FvSdk::ERR_CAT_PARAM:
                return '核验信息有误:' . $msg;
            case FvSdk::ERR_CAT_TEMP:
            default:
                return 'FV接口暂时不可用,请稍后再试。(' . $msg . ')';
        }
    }

    protected function translateResultMsg($resultMessage)
    {
        static $map = [
            'SUCCESS'                       => '核验成功',
            'PASS_LIVING_NOT_THE_SAME'      => '活体检测通过,但照片与身份证信息比对非同一人,核验未通过。',
            'NO_ID_CARD_NUMBER'             => '身份证号码不存在,请核对后重试。',
            'ID_NUMBER_NAME_NOT_MATCH'      => '身份证号与姓名不匹配,请核对后重试。',
            'NO_FACE_FOUND'                 => '上传的照片中未检测到人脸,请上传清晰正脸照后重试。',
            'NO_ID_PHOTO'                   => '系统未查询到该身份证对应的参考照片,请稍后重试。',
            'PHOTO_FORMAT_ERROR'            => '参考照片格式错误,请更换清晰 JPG/PNG 照片后重试。',
            'DATA_SOURCE_ERROR'             => '公安数据源临时异常,请稍后重试。',
            'INTERNAL_ERROR'                => '服务器内部错误,请稍后重试。',
            'FAIL_LIVING_FACE_ATTACK'       => '活体检测未通过(存在照片翻拍/攻击特征),请正对摄像头配合动作后重试。',
            'NOT_STARTED'                   => '核验尚未开始,请先前往认证页面完成操作。',
            'PROCESSING'                    => '核验进行中,请稍候...',
            'FAILED'                        => '核验流程异常结束,请重新发起。',
            'CANCELLED'                     => '您已主动取消本次核验,可重新发起。',
            'TIMEOUT'                       => '等待核验超时,请重新发起并尽快完成。',
            'SUPPORT_ERROR'                 => '当前浏览器不支持人脸核验(需支持 webRTC),请改用最新版 Chrome/Edge。',
            'PERMISSIONS_ERROR'             => '摄像头权限被禁止,请允许浏览器访问摄像头后刷新重试。',
            'OTHER_ERROR'                   => '摄像头/WebRTC 连接异常,请检查网络与摄像头后重试。',
        ];
        $k = (string)$resultMessage;
        return isset($map[$k]) ? $map[$k] : '';
    }

    // =========================================================
    // 辅助：本机实名记录读写
    // =========================================================
    protected function writeStatusByMode($mode, array $data)
    {
        $this->writePersonalStatus($data);
    }

    protected function writePersonalStatus(array $data)
    {
        if (function_exists('updatePersonalCertifiStatus')) {
            \updatePersonalCertifiStatus($data);
        }
        $this->fallbackWrite('personal', $data);
    }

    protected function fallbackWrite($mode, array $data)
    {
        try {
            $uid = $this->resolveCurrentUid([]);
            if ($uid <= 0 || !class_exists('think\Db')) return;
            $tables = ['im_host_user_certification','host_user_certification','im_certification_personal','certification_personal'];
            foreach ($tables as $tbl) {
                try {
                    $rec = \think\Db::name($tbl)->where('uid', $uid)->order('id desc')->find();
                    if ($rec) {
                        $update = [];
                        if (isset($data['status'])) $update['status'] = $data['status'];
                        if (isset($data['auth_fail'])) $update['auth_fail'] = $data['auth_fail'];
                        if (array_key_exists('certify_id', $data)) $update['certify_id'] = $data['certify_id'];
                        if (isset($data['notes'])) $update['notes'] = $data['notes'];
                        if (!empty($update)) {
                            \think\Db::name($tbl)->where('id', $rec['id'])->update($update);
                        }
                        return;
                    }
                } catch (\Throwable $_) {}
            }
        } catch (\Throwable $_) {}
    }

    protected function getCurrentUserCertiRecord($certifi = [])
    {
        try {
            $uid = $this->resolveCurrentUid($certifi);
            if ($uid <= 0 || !class_exists('think\Db')) return [];

            $tables = [
                'im_host_user_certification',
                'host_user_certification',
                'im_certification_personal',
                'certification_personal',
                'im_user_certification',
                'user_certification',
            ];
            foreach ($tables as $tbl) {
                try {
                    $row = \think\Db::name($tbl)->where('uid', $uid)->order('id desc')->find();
                    if ($row) return $row;
                } catch (\Throwable $_) {}
            }
            try {
                $like = \think\Db::query("SHOW TABLES LIKE '%certif%'");
                if (!empty($like)) {
                    foreach ($like as $r) {
                        $tbl = array_values($r)[0] ?? '';
                        if ($tbl === '') continue;
                        try {
                            $row = \think\Db::name($tbl)->where('uid', $uid)->order('id desc')->find();
                            if ($row) return $row;
                        } catch (\Throwable $_) {}
                    }
                }
            } catch (\Throwable $_) {}
        } catch (\Throwable $_) {}
        return [];
    }

    protected function resolveCertifyId($certifi)
    {
        $candidateKeys = ['certify_id', 'certif_id', 'certifi_id', 'certifyId', 'certifiId', 'certifId'];

        if (is_array($certifi)) {
            foreach ($candidateKeys as $k) {
                $v = trim((string)($certifi[$k] ?? ''));
                if ($v !== '') return $v;
            }
            $nested = $certifi['certifi'] ?? null;
            if (is_array($nested)) {
                foreach ($candidateKeys as $k) {
                    $v = trim((string)($nested[$k] ?? ''));
                    if ($v !== '') return $v;
                }
            }
        }

        try {
            $rec = $this->getCurrentUserCertiRecord($certifi);
            if (!empty($rec) && is_array($rec)) {
                foreach (array_merge($candidateKeys, ['task_id', 'certification_id']) as $k) {
                    $v = trim((string)($rec[$k] ?? ''));
                    if ($v !== '') return $v;
                }
            }
        } catch (\Throwable $_) {}

        return '';
    }

    protected function resolveCurrentUid($certifi = [])
    {
        $uid = 0;

        if (is_array($certifi)) {
            $uid = (int)($certifi['uid'] ?? $certifi['user_id'] ?? 0);
            if ($uid <= 0 && is_array($certifi['certifi'] ?? null)) {
                $uid = (int)($certifi['certifi']['uid'] ?? $certifi['certifi']['user_id'] ?? 0);
            }
            if ($uid > 0) return $uid;
        }

        try {
            if (class_exists('think\Session')) {
                foreach (['user_id', 'uid', 'userid', 'user.id', 'userinfo.id', 'login_uid'] as $k) {
                    $uid = (int)\think\Session::get($k);
                    if ($uid > 0) return $uid;
                }
            }
            if (isset($_SESSION)) {
                foreach (['user_id', 'uid', 'userid', 'userinfo.id'] as $k) {
                    if (isset($_SESSION[$k])) {
                        $uid = (int)$_SESSION[$k];
                        if ($uid > 0) return $uid;
                    }
                }
            }
        } catch (\Throwable $_) {}

        try {
            if (class_exists('think\Cookie')) {
                foreach (['user_id', 'uid', 'userid'] as $k) {
                    $uid = (int)\think\Cookie::get($k);
                    if ($uid > 0) return $uid;
                }
            }
        } catch (\Throwable $_) {}

        if ($uid <= 0 && function_exists('session')) {
            foreach (['user_id', 'uid', 'userid'] as $k) {
                $v = \session($k);
                if ($v) {
                    $uid = (int)$v;
                    if ($uid > 0) return $uid;
                }
            }
        }

        if ($uid <= 0 && function_exists('request')) {
            try {
                $uid = (int)(\request()->uid ?? \input('uid/d', 0));
            } catch (\Throwable $_) {}
        }

        return $uid;
    }

    protected function resolveDomain()
    {
        try {
            if (function_exists('request') && is_object(\request())) {
                $dom = \request()->domain();
                if ($dom) return rtrim($dom, '/');
            }
        } catch (\Throwable $_) {}
        $scheme = 'http';
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') $scheme = 'https';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') $scheme = 'https';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        return $host ? ($scheme . '://' . $host) : '';
    }

    // =========================================================
    // 辅助：轮询计数（文件存储）
    // =========================================================
    protected function trackPollAndReturn($certifyId, $status, $msg, $type = 'total', $max = self::MAX_POLL_COUNT)
    {
        $cnt = $this->incPollCounter($certifyId, $type);
        if ($cnt >= $max) {
            return [
                'status' => 2,
                'msg'    => '核验状态查询超时,请稍后重新发起身份核验。',
            ];
        }
        return ['status' => $status, 'msg' => $msg];
    }

    protected function incPollCounter($certifyId, $type)
    {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/starloft_fv_poll';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $file = $dir . '/' . $type . '_' . md5((string)$certifyId) . '.txt';

        $now  = time();
        $cnt  = 0;
        if (is_file($file)) {
            $raw   = @file_get_contents($file);
            $parts = explode('|', (string)$raw);
            if (count($parts) === 2 && (int)$parts[1] >= $now) {
                $cnt = (int)$parts[0];
            }
        }
        $cnt++;
        @file_put_contents($file, $cnt . '|' . ($now + 86400));
        return $cnt;
    }

    // =========================================================
    // 辅助：通用 HTML 输出
    // =========================================================
    protected function buildAuthHtml($authUrl)
    {
        $url = htmlspecialchars($authUrl, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<div class="kyc-auth-container" style="text-align: center; padding: 20px;">
    <h5 class="pt-2 font-weight-bold h5 py-4">正在跳转到人脸核验页面...</h5>
    <p>如果页面未自动跳转,请点击下方按钮(<b>请勿反复刷新或多次点击</b>,以免重复创建核验任务扣费)</p>
    <a href="{$url}" class="btn btn-primary" target="_blank">前往认证</a>
</div>
<script>
(function(){
    var opened = false;
    function tryOpen(){ if(!opened){ opened=true; window.open('{$url}', '_blank'); } }
    setTimeout(tryOpen, 800);
    document.addEventListener && document.addEventListener('click', function once(){
        tryOpen();
        document.removeEventListener('click', once);
    }, {once:true});
})();
</script>
HTML;
    }

    protected function failHtml($msg)
    {
        $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        return "<h3 class=\"pt-2 font-weight-bold h2 py-4\" style=\"color: #f56c6c;\"><i class=\"fa fa-exclamation-circle\"></i> {$m}</h3>";
    }

    protected function successHtml()
    {
        return "<h3 class=\"pt-2 font-weight-bold h2 py-4\" style=\"color: #19be6b;\"><i class=\"fa fa-check-circle\"></i> 身份核验已通过,结果同步中...</h3>";
    }

    protected function continuePollHtml($certifyId, $tip)
    {
        $t   = htmlspecialchars($tip, ENT_QUOTES, 'UTF-8');
        $cid = htmlspecialchars($certifyId, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<div class="kyc-auth-container" style="text-align:center;padding:20px;">
    <h5 class="pt-2 font-weight-bold h5 py-4">
        <i class="fa fa-spinner fa-spin"></i> {$t}
    </h5>
    <p class="text-muted small">任务流水号: {$cid}</p>
</div>
<script>
(function(){
    var stop   = false;
    var tick   = 0;
    var doPoll = function loop(){
        if (stop) return;
        if (typeof window.pollCertifyStatus === 'function') { try{ window.pollCertifyStatus(); setTimeout(loop, 3000); return; }catch(e){} }
        if (typeof window.checkCertifyStatus === 'function') { try{ window.checkCertifyStatus(); setTimeout(loop, 3000); return; }catch(e){} }
        var onResp = function(json){
            try{
                var d = (typeof json === 'string') ? JSON.parse(json) : json;
                if (!d || typeof d.status === 'undefined') { setTimeout(loop, 3000); return; }
                if (d.status === 1 || d.status === 2) { stop = true; location.reload(); return; }
                tick++;
                if (tick > 180) { stop = true; return; }
                setTimeout(loop, 3000);
            }catch(e){ setTimeout(loop, 3000); }
        };
        if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.ajax === 'function') {
            window.jQuery.ajax({
                url:  '/certification/star_loft_fv_for_zjmf_mfcw/getStatus',
                type: 'POST',
                dataType: 'json',
                data: { certif_id: '{$cid}' },
                success: onResp,
                error:   function(){ setTimeout(loop, 3000); }
            });
        } else {
            var x = new XMLHttpRequest();
            x.open('POST', '/certification/star_loft_fv_for_zjmf_mfcw/getStatus', true);
            x.setRequestHeader('Content-Type','application/x-www-form-urlencoded;charset=UTF-8');
            x.onload = function(){ onResp(x.responseText); };
            x.onerror= function(){ setTimeout(loop, 3000); };
            x.send('certif_id=' + encodeURIComponent('{$cid}'));
        }
    };
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(doPoll, 500);
    } else {
        document.addEventListener('DOMContentLoaded', function(){ setTimeout(doPoll, 500); });
    }
})();
</script>
HTML;
    }
}