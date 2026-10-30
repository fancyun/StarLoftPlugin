<?php
/**
 * 星楼网络实名认证 —— 平台异步通知接收
 *
 * 配置给平台的 notify_url（发起认证时上送）：{站点}/user/starloftnotify.php
 * 平台以 JSON POST 推送 biz_no/cost/result_code/result_message/status/sign，
 * 本文件校验签名后按流水号回写商户实名状态；只有处理完成才返回 2xx，暂时失败交由平台补推。
 */
include("../includes/common.php");

$respond = static function($httpStatus, $payload){
	if(!headers_sent())http_response_code((int)$httpStatus);
	exit(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if(!is_array($data) || empty($data)){
	$data = $_POST;
}
if(!is_array($data) || empty($data)){
	$respond(400, ['code'=>400, 'message'=>'empty body']);
}

// 未知/未开启通道时也返回 200，避免平台无谓重推
if($conf['cert_open'] != 7){
	$respond(200, ['code'=>200, 'message'=>'channel disabled']);
}

try{
	$certify = new \lib\StarLoftCertify($conf['cert_starloft_key'], $conf['cert_starloft_secret'], $conf['cert_starloft_url']);
}catch(Exception $e){
	$respond(500, ['code'=>500, 'message'=>$e->getMessage()]);
}

$sign = (string)($data['sign'] ?? '');
if(!$certify->verifyNotifySign($data, $sign)){
	$respond(401, ['code'=>401, 'message'=>'signature verification failed']);
}

$biz_no = trim((string)($data['biz_no'] ?? ''));
if($biz_no === ''){
	$respond(400, ['code'=>400, 'message'=>'missing biz_no']);
}

// 流水号在发起认证时写入 certtoken；通知地址带 uid 参数时一并校验归属
$userrow = $DB->getRow("SELECT uid,cert FROM pre_user WHERE certtoken=:certtoken LIMIT 1", [':certtoken'=>$biz_no]);
if(!$userrow){
	// 可能是 notify 先于发起请求后的本地写入到达；返回非 2xx 让平台重试。
	$respond(503, ['code'=>503, 'message'=>'record not found yet']);
}
$uid = intval($userrow['uid']);
if(isset($_GET['uid']) && intval($_GET['uid']) > 0 && intval($_GET['uid']) != $uid){
	$respond(403, ['code'=>403, 'message'=>'uid mismatch']);
}

// 通知已验签；查询接口不可用或尚未落地终态时，使用通知内的终态，避免确认后丢失结果。
$notifyStatus = $certify->normalizeNotifyStatus($data);
$status = $notifyStatus;
try{
	$result = $certify->query($biz_no, 1);
	$queryStatus = intval($result['status'] ?? 4);
	if($queryStatus == 1 || $queryStatus == 2)$status = $queryStatus;
}catch(Exception $e){
	// 查询失败时保留已验签通知的状态，继续完成本地回写。
}

if($status == 1){
	if(intval($userrow['cert']) != 1){
		$updated = $DB->exec("update `pre_user` set `cert`=1,`certtime`=NOW() where `uid`=:uid and `cert`=0", [':uid'=>$uid]);
		if($updated){
			if($conf['cert_money'] > 0){
				changeUserMoney($uid, $conf['cert_money'], false, '实名认证');
			}
		}else{
			// 并发通知时另一请求可能已完成回写；只有仍未认证才要求平台重试。
			$latest = $DB->getRow("SELECT cert FROM pre_user WHERE uid=:uid LIMIT 1", [':uid'=>$uid]);
			if(!$latest || intval($latest['cert'] ?? 0) != 1){
				$respond(503, ['code'=>503, 'message'=>'failed to update certification status']);
			}
		}
	}
	$respond(200, ['code'=>0, 'message'=>'success']);
}

if($status == 2){
	$respond(200, ['code'=>0, 'message'=>'status=2']);
}

// 平台的实名通知应当携带终态；非终态不做成功确认，交由平台补推。
$respond(503, ['code'=>503, 'message'=>'notification did not contain a terminal status']);
