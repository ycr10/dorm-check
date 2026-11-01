<?php
declare(strict_types=1);

use DormCheck\ApiError;
use DormCheck\Auth;
use DormCheck\Domain;
use DormCheck\Support;

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

function respond(array $payload, int $status = 200): never
{
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload + ['csrf'=>$_SESSION['csrf'] ?? null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    exit;
}

function originOf(string $url): ?string
{
    $p=parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return null;
    $scheme=strtolower($p['scheme']); if (!in_array($scheme,['http','https'],true)) return null;
    $port=$p['port'] ?? ($scheme==='https'?443:80);
    return $scheme.'://'.strtolower($p['host']).':'.$port;
}

try {
    [$config,$db]=require dirname(__DIR__,2).'/src/bootstrap.php';
    $action=$_GET['action'] ?? 'session';
    if (!is_string($action) || !preg_match('/^[a-z][a-z_.]{0,63}$/D',$action)) throw new ApiError('接口不存在','NOT_FOUND',404);
    $action=str_replace('.','_',$action);
    $reads=['session','tasks','task_history','dashboard','reports','followups','inspections','roster','grants','settings','audit','draft_get'];
    $authActions=['session','login','activate','reset_password','logout','logout_all','change_password','totp_setup','totp_confirm','reauth'];
    $userActions=['user_create','user_reset','user_status','users_list'];
    $domainActions=['tasks','task_history','dashboard','reports','followups','inspections','roster','grants','settings','audit','draft_get','draft_save','report_initial','report_update','report_correct','report_snapshot','export','followup_save','inspection_save','import_preview','import_commit','student_retire','grant_save','grant_revoke','exemption_save','exemption_revoke','settings_save','task_close','task_reopen','task_roster_adjust'];
    if (!in_array($action,array_merge($authActions,$userActions,$domainActions),true)) throw new ApiError('接口不存在','NOT_FOUND',404);
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    $expected=in_array($action,$reads,true)?'GET':'POST';
    if ($method!==$expected) { header('Allow: '.$expected); throw new ApiError('请求方法不正确','METHOD_NOT_ALLOWED',405); }
    if ($method==='POST') {
        $type=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($type!=='application/json') throw new ApiError('写请求必须使用 JSON','UNSUPPORTED_MEDIA_TYPE',415);
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>3*1024*1024) throw new ApiError('请求过大','PAYLOAD_TOO_LARGE',413);
        $origin=$_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        if ($origin==='' || originOf($origin)===null || originOf($origin)!==originOf($config['app_url'])) throw new ApiError('请求来源不正确，请核对访问地址','ORIGIN_INVALID',403);
        $csrf=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'],$csrf)) throw new ApiError('安全校验已过期，请刷新后重试','CSRF_INVALID',403);
        $raw=file_get_contents('php://input',false,null,0,3*1024*1024+1);
        if ($raw===false || strlen($raw)>3*1024*1024) throw new ApiError('请求过大','PAYLOAD_TOO_LARGE',413);
        try { $object=json_decode($raw,false,64,JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ApiError('请求不是有效 JSON','INVALID_JSON',400); }
        if (!$object instanceof stdClass) throw new ApiError('JSON 顶层必须是对象','INVALID_JSON',400);
        $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    } else {
        $data=$_GET; unset($data['action']);
        foreach ($data as $value) if (!is_string($value)) throw new ApiError('查询参数格式不正确','INVALID_QUERY',400);
    }
    $maintenance=$config['storage_path'].'/maintenance.json';
    if (is_file($maintenance)) {
        $state=json_decode((string)file_get_contents($maintenance),true);
        if (($state['block_all'] ?? false) || !in_array($action,$authActions,true)) throw new ApiError('系统维护中，业务访问暂停；账号恢复可按维护人员指引进行','MAINTENANCE',503);
    }
    $auth=new Auth($db,$config);
    if (in_array($action,$authActions,true)) {
        $result=$auth->handle($action,$data);
    } elseif (in_array($action,$userActions,true)) {
        $user=$auth->requireUser(true);
        $result=$auth->manage($action==='users_list'?'users.list':$action,$user,$data);
    } else {
        $user=$auth->requireUser(($_SERVER['HTTP_X_PASSIVE_REQUEST'] ?? '')!=='1');
        $result=(new Domain($db,$config))->handle($action,$user,$data);
    }
    if ($action==='export') {
        // Recheck the account immediately before streaming; no public filename routes.
        $user=$auth->requireUser(); $auth->requireRecent($user);
        if (!in_array($user['role'],['admin','committee'],true)) throw new ApiError('没有导出权限','FORBIDDEN',403);
        $base=realpath($config['storage_path'].'/exports'); $path=realpath($result['download_path']);
        if ($base===false || $path===false || dirname($path)!==$base || !is_file($path)) throw new RuntimeException('Invalid private export path');
        try {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header("Content-Disposition: attachment; filename=\"dorm-report.xlsx\"; filename*=UTF-8''".rawurlencode($result['filename']));
            header('Content-Length: '.filesize($path));
            session_write_close(); readfile($path);
        } finally { if (is_file($path)) unlink($path); }
        exit;
    }
    respond(['ok'=>true,'data'=>$result]);
} catch (ApiError $e) {
    respond(['ok'=>false,'error'=>['message'=>$e->getMessage(),'code'=>$e->errorCode]],$e->status);
} catch (Throwable $e) {
    $trace=bin2hex(random_bytes(8));
    error_log('DormCheck '.$trace.' '.get_class($e).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
    respond(['ok'=>false,'error'=>['message'=>'服务暂时不可用，请联系维护人员并提供错误编号 '.$trace,'code'=>'INTERNAL_ERROR','trace_id'=>$trace]],500);
}
