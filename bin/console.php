<?php
declare(strict_types=1);

use DormCheck\ApiError;
use DormCheck\Audit;
use DormCheck\Auth;
use DormCheck\Backup;
use DormCheck\Database;
use DormCheck\Domain;
use DormCheck\Grade;
use DormCheck\Schema;
use DormCheck\Security;
use DormCheck\Support;

if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/src/Core.php';
require_once dirname(__DIR__).'/src/Schema.php';
require_once dirname(__DIR__).'/src/Grade.php';
require_once dirname(__DIR__).'/src/Auth.php';
require_once dirname(__DIR__).'/src/Xlsx.php';
require_once dirname(__DIR__).'/src/Reports.php';
require_once dirname(__DIR__).'/src/Domain.php';
require_once dirname(__DIR__).'/src/Backup.php';
date_default_timezone_set('Asia/Shanghai');

function output(array $value): void { echo Support::json($value).PHP_EOL; }

function options(array $arguments): array
{
    $out=[];
    foreach ($arguments as $arg) {
        if (!preg_match('/^--([a-z-]+)=(.*)$/sD',$arg,$m) || isset($out[$m[1]])) throw new ApiError('参数请使用 --名称=值 格式，不能重复');
        $out[$m[1]]=$m[2];
    }
    return $out;
}

function requireOptions(array $options, array $allowed): void
{
    foreach ($options as $key=>$v) if (!in_array($key,$allowed,true)) throw new ApiError('不支持的参数：--'.$key);
}

function migrationMap(array $args): ?array
{
    if (!isset($args['map-file'])) return null;
    $path=$args['map-file'];
    if ($path==='' || !is_file($path) || is_link($path) || filesize($path)>262144) throw new ApiError('映射文件无效或超过 256 KB');
    try { $object=json_decode((string)file_get_contents($path),false,64,JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ApiError('映射文件必须为 UTF-8 JSON 对象'); }
    if (!$object instanceof stdClass) throw new ApiError('映射文件必须是历史班名到目标班名的 JSON 对象');
    $mapping=get_object_vars($object);
    foreach ($mapping as $name=>$target) if (!is_string($target)) throw new ApiError('每个映射目标都必须是班级名称');
    return $mapping;
}

function checks(array $config, ?PDO $db = null): array
{
    $driver=str_starts_with($config['db_dsn'],'sqlite:')?'sqlite':'mysql';
    $items=[];
    foreach (['PDO','openssl','mbstring','zip','xmlwriter','pdo_'.$driver] as $extension) $items[]=['check'=>'extension:'.$extension,'ok'=>extension_loaded($extension)];
    $items[]=['check'=>'Argon2id','ok'=>defined('PASSWORD_ARGON2ID') && in_array('argon2id',password_algos(),true)];
    $items[]=['check'=>'PHP security version (production required)','ok'=>Security::versionSafe(),'warning'=>$config['env']!=='production'];
    $items[]=['check'=>'session.upload_progress.enabled=0','ok'=>in_array(ini_get('session.upload_progress.enabled'),['0',''],true)];
    $items[]=['check'=>'app_key:32 bytes','ok'=>strlen(base64_decode($config['app_key'],true) ?: '')===32];
    $items[]=['check'=>'storage writable','ok'=>is_dir($config['storage_path']) && is_writable($config['storage_path'])];
    $items[]=['check'=>'production HTTPS URL','ok'=>$config['env']!=='production' || str_starts_with($config['app_url'],'https://')];
    if ($db) {
        $items[]=['check'=>'schema version','ok'=>Schema::get($db,'schema_version')===2];
        if ($driver==='mysql') {
            $engines=Support::all($db,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
            $items[]=['check'=>'MySQL transactional tables','ok'=>count($engines)>0 && count(array_filter($engines,fn($e)=>strtoupper((string)$e['ENGINE'])!=='INNODB'))===0];
        }
    }
    return $items;
}

function init(array $args): array
{
    requireOptions($args,['env','url','db','class']);
    $root=dirname(__DIR__); $configPath=getenv('DORM_CONFIG') ?: $root.'/config/local.php';
    if (file_exists($configPath)) throw new ApiError('配置已经存在，初始化不会覆盖。请使用 doctor 或受控 migrate');
    if (!is_dir(dirname($configPath))) throw new ApiError('配置的父目录必须预先建立并设置私有权限');
    $env=$args['env'] ?? 'development'; $url=$args['url'] ?? 'http://127.0.0.1:8765'; $driver=$args['db'] ?? 'sqlite';
    if (!in_array($env,['development','production'],true) || !in_array($driver,['sqlite','mysql'],true)) throw new ApiError('环境或数据库类型不支持');
    $p=parse_url($url);
    if (!$p || !isset($p['scheme'],$p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !in_array($p['path'] ?? '',['','/'],true)) throw new ApiError('URL 应为不带路径、凭据或参数的站点地址');
    if ($env==='production' && $p['scheme']!=='https') throw new ApiError('正式环境必须使用 HTTPS');
    if ($env==='development' && (!in_array($p['scheme'],['http','https'],true) || !in_array($p['host'],['127.0.0.1','localhost','[::1]','::1'],true))) throw new ApiError('本地体验只接受回环地址');
    $storage=$root.'/storage';
    if (!is_dir($storage) && !mkdir($storage,0700,true)) throw new RuntimeException('Cannot create private storage');
    $class=Support::text($args['class'] ?? '示例班级',100); if ($class==='') throw new ApiError('班级不能为空');
    $config=['env'=>$env,'app_url'=>rtrim($url,'/'),'class_name'=>$class,'timezone'=>'Asia/Shanghai','check_time'=>'22:30','due_time'=>'22:35','db_dsn'=>$driver==='sqlite'?'sqlite:'.$storage.'/dorm.sqlite':(getenv('DORM_DB_DSN') ?: ''),'db_user'=>$driver==='mysql'?(getenv('DORM_DB_USER') ?: ''):'','db_password'=>$driver==='mysql'?(getenv('DORM_DB_PASSWORD') ?: ''):'','app_key'=>base64_encode(random_bytes(32)),'storage_path'=>$storage,'idle_seconds'=>1800,'session_seconds'=>28800,'reauth_seconds'=>300];
    if ($driver==='mysql' && (!str_starts_with($config['db_dsn'],'mysql:') || $config['db_user']==='')) throw new ApiError('请从私有环境变量设置 MySQL DSN、账号及密码');
    $items=checks($config);
    foreach ($items as $item) if (!$item['ok'] && !($item['warning'] ?? false)) throw new ApiError('环境检查未通过：'.$item['check']);
    $db=Database::connect($config);
    $existing=$driver==='sqlite'?Support::all($db,"SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"):Support::all($db,'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
    if ($existing) throw new ApiError('目标数据库并非空库，初始化已拒绝；不会删除或重建已有数据');
    $stored=$config; unset($stored['db_password']);
    $text="<?php\ndeclare(strict_types=1);\n\$config = ".var_export($stored,true).";\n";
    if ($driver==='mysql') $text.="\$config['db_dsn'] = getenv('DORM_DB_DSN') ?: \$config['db_dsn'];\n\$config['db_user'] = getenv('DORM_DB_USER') ?: \$config['db_user'];\n\$config['db_password'] = getenv('DORM_DB_PASSWORD') ?: '';\n";
    else $text.="\$config['db_password'] = '';\n";
    $text.="return \$config;\n";
    $handle=fopen($configPath,'xb'); if (!$handle) throw new ApiError('不能创建私有配置文件');
    $success=false;
    try {
        (new Domain($db,$config))->install();
        (new Domain($db,$config))->prepare(substr(Support::now(),0,10));
        if (fwrite($handle,$text)!==strlen($text)) throw new RuntimeException('Configuration write failed');
        $success=true;
    } finally { fclose($handle); if (!$success && is_file($configPath)) unlink($configPath); }
    chmod($configPath,0600);
    return ['ok'=>true,'config'=>$configPath,'database'=>$driver,'class_name'=>$class,'next'=>'使用 user:create 建立首名管理员，或在空的 development 数据库运行 demo。没有默认密码。'];
}

try {
    $command=$argv[1] ?? 'help'; $args=options(array_slice($argv,2));
    if ($command==='help') {
        output(['commands'=>['init --env=development --url=http://127.0.0.1:8765 --db=sqlite --class=示例班级','doctor','migrate:preview','migrate --confirm=GRADE','user:create --student-no=本人学号 --name=姓名 --role=admin|committee','user:reset --student-no=本人学号','user:disable --student-no=本人学号','mfa:reset --student-no=已核实学号','demo','tick','backup --output=新备份完整路径.dcb','maintenance:on','restore --input=备份完整路径.dcb --confirm=RESTORE','maintenance:off --confirm=REVIEWED','prune --before=YYYY-MM-DD --confirm=PRUNE']]); exit;
    }
    if ($command==='init') { output(init($args)); exit; }
    [$config,$db]=require dirname(__DIR__).'/src/bootstrap.php';
    $auth=new Auth($db,$config); $domain=new Domain($db,$config);
    switch ($command) {
        case 'doctor':
            requireOptions($args,[]); $items=checks($config,$db);
            $ok=count(array_filter($items,fn($i)=>!$i['ok'] && !($i['warning'] ?? false)))===0;
            output(['ok'=>$ok,'php_version'=>PHP_VERSION,'environment'=>$config['env'],'checks'=>$items,'maintenance'=>is_file($config['storage_path'].'/maintenance.json'),'job_runs'=>Support::all($db,'SELECT * FROM job_runs ORDER BY id DESC LIMIT 5'),'note'=>'CLI 检查不代表 PHP-FPM、HTTPS、MySQL、浏览器或恢复演练已经通过。Windows 重建包还须核对官方包名及 SHA-256。']); exit($ok?0:1);
        case 'migrate:preview':
            requireOptions($args,['map-file']); output(Grade::preview($db,migrationMap($args))); break;
        case 'migrate':
            requireOptions($args,['confirm','map-file']);
            if (($args['confirm'] ?? '')!=='GRADE' || !is_file($config['storage_path'].'/maintenance.json')) throw new ApiError('请先备份并启用维护模式，再使用 --confirm=GRADE');
            Grade::install($db,migrationMap($args)); output(['ok'=>true,'schema_version'=>2]); break;
        case 'user:create':
            requireOptions($args,['student-no','name','role']);
            output($auth->createUser(Support::text($args['student-no'] ?? '',64),Support::text($args['name'] ?? '',100),$args['role'] ?? 'committee')); break;
        case 'user:reset':
        case 'user:disable':
        case 'mfa:reset':
            requireOptions($args,['student-no']);
            $u=Support::one($db,'SELECT * FROM users WHERE student_no=?',[Support::text($args['student-no'] ?? '',64)]);
            if (!$u) throw new ApiError('账号不存在');
            if ($command==='user:reset') { output($auth->issueToken((int)$u['id'],'reset')); break; }
            Schema::transaction($db,function () use ($db,$u,$command): void {
                if ($command==='user:disable') {
                    Support::exec($db,"UPDATE users SET status='disabled',auth_version=auth_version+1,updated_at=? WHERE id=?",[Support::now(),$u['id']]);
                    Support::exec($db,'UPDATE grants SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL',[Support::now(),$u['id']]);
                } else {
                    if ($u['status']==='disabled') throw new ApiError('不能为停用账号重建验证器');
                    Support::exec($db,'UPDATE users SET totp_secret=NULL,totp_enabled=0,totp_last_step=-1,auth_version=auth_version+1,updated_at=? WHERE id=?',[Support::now(),$u['id']]);
                    Support::exec($db,'DELETE FROM recovery_codes WHERE user_id=?',[$u['id']]);
                }
                Support::exec($db,'UPDATE auth_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL',[time(),$u['id']]);
                Audit::write($db,null,$command,['user_id'=>(int)$u['id'],'controlled_cli'=>true]);
            });
            output(['ok'=>true,'message'=>$command==='user:disable'?'账号及其授权已停用':'旧会话和二次验证已撤销；若该账号被当前策略指定，本人须重新绑定验证器才可进入业务数据']); break;
        case 'demo': requireOptions($args,[]); output($domain->seedDemo()); break;
        case 'tick':
            requireOptions($args,[]);
            if (is_file($config['storage_path'].'/maintenance.json')) throw new ApiError('维护期间调度暂停，恢复后会补跑');
            output($domain->tick()); break;
        case 'backup':
            requireOptions($args,['output']); if (empty($args['output'])) throw new ApiError('请指定全新备份文件 --output');
            output((new Backup($db,$config))->create($args['output'])); break;
        case 'maintenance:on': requireOptions($args,[]); Backup::maintenance($config); output(['maintenance'=>true]); break;
        case 'restore':
            requireOptions($args,['input','confirm']);
            if (($args['confirm'] ?? '')!=='RESTORE' || empty($args['input'])) throw new ApiError('恢复会替换业务数据。请指定 --input=... --confirm=RESTORE，先完成现状备份');
            output((new Backup($db,$config))->restore($args['input'])); break;
        case 'maintenance:off':
            requireOptions($args,['confirm']); $marker=$config['storage_path'].'/maintenance.json';
            if (!is_file($marker)) { output(['maintenance'=>false]); break; }
            $state=json_decode((string)file_get_contents($marker),true);
            if (($state['block_all'] ?? false)) throw new ApiError('上次恢复没有完成，禁止直接开放；请排查后完成恢复');
            if (($state['recovered'] ?? false) && ($args['confirm'] ?? '')!=='REVIEWED') throw new ApiError('请核实当前名单、到期数据、账号及重新建立的授权，再传 --confirm=REVIEWED');
            $admins=Support::all($db,"SELECT student_no,role,totp_enabled FROM users WHERE role='admin' AND status='active'");
            $ready=array_filter($admins,fn($admin)=>!Auth::totpPolicy($db,$admin)['totp_required'] || $admin['totp_enabled']);
            if (!$ready) throw new ApiError('至少需要一名有效且符合当前两步验证策略的管理员，不能提前开放业务');
            Audit::write($db,null,'maintenance.off',['reviewed'=>true]); unlink($marker); output(['maintenance'=>false]); break;
        case 'prune':
            requireOptions($args,['before','confirm']);
            if (($args['confirm'] ?? '')!=='PRUNE') throw new ApiError('清理不可撤销，请先备份并传 --confirm=PRUNE');
            $cutoff=$args['before'] ?? (new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->modify('-'.(int)Schema::get($db,'retention_days',180).' days')->format('Y-m-d');
            output($domain->prune($cutoff)); break;
        default: throw new ApiError('未知命令，请运行 help');
    }
} catch (Throwable $e) {
    fwrite(STDERR,Support::json(['ok'=>false,'error'=>$e->getMessage(),'code'=>$e instanceof ApiError?$e->errorCode:'CLI_ERROR']).PHP_EOL); exit(1);
}
