<?php
declare(strict_types=1);

use DormCheck\ApiError;
use DormCheck\DayRebuilder;
use DormCheck\Support;

if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

try {
    require_once dirname(__DIR__).'/src/Core.php';
    require_once dirname(__DIR__).'/src/DayRebuilder.php';
    $args=DayRebuilder::arguments(array_slice($argv,1));
    [$config,$db]=require dirname(__DIR__).'/src/bootstrap.php';
    require_once dirname(__DIR__).'/src/Backup.php';
    $service=new DayRebuilder($db,$config);
    if ($args['mode']==='preview') {
        $result=$service->preview($args['date']);
    } else {
        $result=$service->rebuild($args['date'],$args['confirm'],$args['backup'],true);
    }
    echo Support::json(['ok'=>true,'mode'=>$args['mode'],'data'=>$result]).PHP_EOL;
} catch (Throwable $e) {
    $message=$e instanceof ApiError?$e->getMessage():'脚本运行失败，请保持维护模式并由维护人员检查私有日志和数据库状态';
    $code=$e instanceof ApiError?$e->errorCode:'CLI_ERROR';
    if (!$e instanceof ApiError) error_log('DormCheck rebuild '.get_class($e).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$message,'code'=>$code],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
