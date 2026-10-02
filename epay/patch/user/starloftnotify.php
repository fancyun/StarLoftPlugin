<?php
/**
 * 星楼网络实名认证 —— 平台异步通知接收
 *
 * 配置给平台的 notify_url（发起认证时上送）：{站点}/user/starloftnotify.php
 * 平台以 JSON POST 推送 biz_no/cost/result_code/result_message/status/sign，
 * 本文件校验签名后按流水号回写商户实名状态，并始终返回 HTTP 200 JSON，避免平台反复补推。
 */
include("../includes/common.php");

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if(!is_array($data) || empty($data)){
	$data = $_POST;
}
if(!is_array($data) || empty($data)){
	exit(json_encode(['code'=>400, 'message'=>'empty body']));
}

// 未知/未开启通道时也返回 200，避免平台无谓重推
if($conf['cert_open'] != 7){
	exit(json_encode(['code'=>200, 'message'=>'channel disabled']));
}

try{
	$certify = new \lib\StarLoftCertify($conf['cert_starloft_key'], $conf['cert_starloft_secret'], $conf['cert_starloft_url']);
}catch(Exception $e){
	exit(json_encode(['code'=>500, 'message'=>$e->getMessage()]));
}

$sign = (string)($data['sign'] ?? '');
if(!$certify->verifyNotifySign($data, $sign)){
	exit(json_encode(['code'=>401, 'message'=>'signature verification failed']));
}

$biz_no = trim((string)($data['biz_no'] ?? ''));
if($biz_no === ''){
	exit(json_encode(['code'=>400, 'message'=>'missing biz_no']));
}

// 流水号在发起认证时写入 certtoken；通知地址带 uid 参数时一并校验归属
$userrow = $DB->getRow("SELECT uid,cert FROM pre_user WHERE certtoken=:certtoken LIMIT 1", [':certtoken'=>$biz_no]);
if(!$userrow){
	exit(json_encode(['code'=>404, 'message'=>'record not found']));
}
$uid = intval($userrow['uid']);
if(isset($_GET['uid']) && intval($_GET['uid']) > 0 && intval($_GET['uid']) != $uid){
	exit(json_encode(['code'=>403, 'message'=>'uid mismatch']));
}

// 以平台查询接口结果为准（失败时回落到通知体里的状态）
$status = intval($data['status'] ?? 4);
try{
	$result = $certify->query($biz_no, 1);
	$status = intval($result['status'] ?? 4);
}catch(Exception $e){
	// 查询失败不回写，等平台下一次补推
	exit(json_encode(['code'=>0, 'message'=>'query failed: '.$e->getMessage()]));
}

if($status == 1){
	if(intval($userrow['cert']) != 1){
		$DB->exec("update `pre_user` set `cert`=1,`certtime`=NOW() where `uid`=:uid and `cert`=0", [':uid'=>$uid]);
		if($conf['cert_money'] > 0){
			changeUserMoney($uid, $conf['cert_money'], false, '实名认证');
		}
	}
	exit(json_encode(['code'=>0, 'message'=>'success']));
}

exit(json_encode(['code'=>0, 'message'=>'status='.$status]));