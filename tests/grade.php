<?php
declare(strict_types=1);
use DormCheck\ApiError;
use DormCheck\Auth;
use DormCheck\Clock;
use DormCheck\Database;
use DormCheck\Domain;
use DormCheck\Grade;
use DormCheck\Schema;
use DormCheck\Support;
foreach (['Core','Schema','Grade','Auth','Xlsx','Reports','Domain','Backup'] as $file) require_once dirname(__DIR__).'/src/'.$file.'.php';
date_default_timezone_set('Asia/Shanghai');
final class GradeClock implements Clock { public string $value='2026-10-29 22:00:00'; public function now(): string { return $this->value; } }
function eq(mixed $a,mixed $b): void { if ($a!==$b) throw new RuntimeException('Expected '.Support::json($b).', got '.Support::json($a)); }
function ok(bool $v,string $message): void { if (!$v) throw new RuntimeException($message); }
function denied(callable $f): void { try { $f(); } catch(ApiError $e) { if ($e->errorCode==='FORBIDDEN') return; throw $e; } throw new RuntimeException('Expected FORBIDDEN'); }
$results=[];
function test(string $name, callable $fn): void { global $results; try { $fn(); $results[]=['name'=>$name,'ok'=>true]; } catch(Throwable $e) { $results[]=['name'=>$name,'ok'=>false,'error'=>$e->getMessage(),'line'=>$e->getLine()]; } }
$clock=new GradeClock();
$config=['env'=>'development','app_url'=>'http://127.0.0.1:8765','class_name'=>'测试甲班','db_dsn'=>'sqlite::memory:','db_user'=>'','db_password'=>'','app_key'=>base64_encode(random_bytes(32)),'storage_path'=>sys_get_temp_dir(),'idle_seconds'=>1800,'session_seconds'=>28800,'reauth_seconds'=>300,'clock'=>$clock];
$db=Database::connect($config); $domain=new Domain($db,$config); $domain->install(); $auth=new Auth($db,$config);
$users=[];
foreach ([['900001','管理员','admin'],['900002','甲班委','committee'],['900003','乙班委','committee']] as [$no,$name,$role]) {
    $created=$auth->createUser($no,$name,$role); Support::exec($db,"UPDATE users SET status='active',totp_enabled=1 WHERE id=?",[$created['user_id']]);
    $users[]=['id'=>$created['user_id'],'student_no'=>$no,'name'=>$name,'role'=>$role,'reauth_until'=>time()+300];
}
[$admin,$a,$b]=$users;
$classes=Grade::classes($db,$admin); $classA=(int)$classes[0]['id'];
$classB=(int)$domain->handle('class_create',$admin,['name'=>'测试乙班'])['class']['id'];
$domain->handle('class_permissions_save',$admin,['user_id'=>$a['id'],'class_ids'=>[$classA]]);
$domain->handle('class_permissions_save',$admin,['user_id'=>$b['id'],'class_ids'=>[$classB]]);
function importClass(Domain $domain,array $user,int $class,string $csv): void {
    $p=$domain->handle('import_preview',$user,['class_id'=>$class,'csv'=>$csv]);
    if ($p['errors']) throw new RuntimeException(Support::json($p['errors']));
    $domain->handle('import_commit',$user,['class_id'=>$class,'csv'=>$csv,'hash'=>$p['hash'],'confirm'=>true]);
}
$head="班级,学号,姓名,楼栋,房间号,寝室长学号\n";
$csvA=$head."测试甲班,100001,虚构甲,甲楼,501,100001\n";
$csvB=$head."测试乙班,100002,虚构乙,甲楼,501,100001\n";
importClass($domain,$admin,$classA,$csvA); importClass($domain,$admin,$classB,$csvB);
$clock->value='2026-10-29 22:31:00'; $domain->tick();
test('新库建立稳定班级并移除宿舍长账号创建',function () use ($db,$auth) { eq(Schema::get($db,'schema_version'),2); try {$auth->createUser('100001','负责人','leader');} catch(ApiError $e) {return;} throw new RuntimeException('leader role accepted'); });
test('单班只能查看自身学生和班级',function () use ($domain,$a,$classA) { $c=$domain->handle('classes',$a,[])['classes']; eq(count($c),1); eq((int)$c[0]['id'],$classA); $r=$domain->handle('roster',$a,[]); eq(count($r['students']),1); eq($r['students'][0]['name'],'虚构甲'); });
test('跨班混住宿舍任务只显示授权成员',function () use ($domain,$a) { $t=$domain->handle('tasks',$a,['date'=>'2026-10-29'])['tasks']; eq(count($t),1); eq(count($t[0]['members']),1); eq($t[0]['members'][0]['name'],'虚构甲'); ok(!str_contains(Support::json($t),'虚构乙'),'other class leaked'); });
test('不可选择未授权班级或更改他班成员',function () use ($domain,$a,$classB,$db) { denied(fn()=>$domain->handle('roster',$a,['class_id'=>$classB])); $b=Support::one($db,"SELECT id FROM students WHERE student_no='100002'"); denied(fn()=>$domain->handle('student_retire',$a,['student_id'=>$b['id'],'reason'=>'测试'])); });
test('班委不得结项或调整混住宿舍整体',function () use ($domain,$a) { $t=$domain->handle('tasks',$a,['date'=>'2026-10-29'])['tasks'][0]; denied(fn()=>$domain->handle('task_close',$a,['task_id'=>$t['id'],'reason'=>'测试'])); denied(fn()=>$domain->handle('task_roster_adjust',$a,['task_id'=>$t['id'],'reason'=>'测试','roster_version'=>$t['roster_version'],'student_ids'=>[]])); });
test('班委初报只影响本人班级',function () use ($domain,$a,$db) { $t=$domain->handle('tasks',$a,['date'=>'2026-10-29'])['tasks'][0]; $domain->handle('report_initial',$a,['task_id'=>$t['id'],'roster_version'=>$t['roster_version'],'version'=>$t['report_version'],'idempotency_key'=>'test-partial-12345','members'=>[['student_id'=>$t['members'][0]['student_id'],'status'=>'present','location'=>'']]]); $b=Support::one($db,"SELECT m.initial_status FROM night_members m WHERE student_no='100002'"); eq($b['initial_status'],'unreported'); });
test('快捷入口读取整寝并完成余下人员',function () use ($config,$db) { $quick=new Domain($db,$config); $id=['student_no'=>'100001','room_number'=>'501']; $t=$quick->quick('quick_lookup',$id,'127.0.0.1')['task']; eq(count($t['members']),2); $members=array_map(fn($m)=>['student_id'=>$m['student_id'],'status'=>'present','location'=>''],$t['members']); $done=$quick->quick('quick_initial',$id+['version'=>$t['version'],'roster_version'=>$t['roster_version'],'members'=>$members,'idempotency_key'=>'test-quick-123456'],'127.0.0.1')['task']; ok((bool)$done['submitted_at'],'room not complete'); });
test('最新和固定日报只包含当前授权学生',function () use ($domain,$admin,$a) { $domain->handle('report_snapshot',$admin,['date'=>'2026-10-29','reason'=>'测试']); $latest=$domain->handle('reports',$a,['date'=>'2026-10-29']); eq($latest['metrics']['total'],1); ok(!str_contains(Support::json($latest),'虚构乙'),'report leaked'); $v=$latest['versions'][0]['id']; $snapshot=$domain->handle('reports',$a,['date'=>'2026-10-29','version'=>$v]); ok(!str_contains(Support::json($snapshot),'虚构乙'),'snapshot leaked'); });
test('班级 Excel 导出不包含其他班级学生',function () use ($domain,$a) { $export=$domain->handle('export',$a,['date'=>'2026-10-29']); $zip=new ZipArchive(); ok($zip->open($export['download_path'])===true,'cannot open XLSX'); $text=''; for($i=0;$i<$zip->numFiles;$i++) $text.=$zip->getFromIndex($i); $zip->close(); unlink($export['download_path']); ok(str_contains($text,'虚构甲'),'authorized student missing'); ok(!str_contains($text,'虚构乙'),'other class leaked to XLSX'); });
test('幂等重试保留原结果且授权变化后拒绝旧缓存',function () use ($domain,$admin,$b,$classA,$classB) {
    $t=$domain->handle('tasks',$b,['date'=>'2026-10-29'])['tasks'][0]; $id=$t['members'][0]['student_id'];
    $payload=['task_id'=>$t['id'],'student_ids'=>[$id],'status'=>'away','location'=>'图书馆','version'=>$t['report_version'],'idempotency_key'=>'replay-old-result-001'];
    $first=$domain->handle('report_update',$b,$payload);
    $domain->handle('report_update',$b,['task_id'=>$t['id'],'student_ids'=>[$id],'status'=>'present','location'=>'','version'=>$first['task']['report_version'],'idempotency_key'=>'replay-new-result-002']);
    eq($domain->handle('report_update',$b,$payload),$first);
    $domain->handle('class_permissions_save',$admin,['user_id'=>$b['id'],'class_ids'=>[$classA,$classB]]);
    try { $domain->handle('report_update',$b,$payload); throw new RuntimeException('scope-changing replay was accepted'); } catch (ApiError $e) { eq($e->errorCode,'SCOPE_CHANGED'); }
    $domain->handle('class_permissions_save',$admin,['user_id'=>$b['id'],'class_ids'=>[$classB]]);
});
test('负责人交接只改变当前关系并保护历史快照',function () use ($domain,$admin,$a,$config,$db) { $task=$domain->handle('tasks',$admin,['date'=>'2026-10-29'])['tasks'][0]; denied(fn()=>$domain->handle('room_leader_change',$a,['room_id'=>$task['room_id'],'student_no'=>'100002','reason'=>'交接'])); $domain->handle('room_leader_change',$admin,['room_id'=>$task['room_id'],'student_no'=>'100002','reason'=>'交接']); $old=Support::one($db,'SELECT leader_no FROM room_tasks WHERE task_id=? AND room_id=?',[$task['task_id'],$task['room_id']]); eq($old['leader_no'],'100001'); $quick=new Domain($db,$config); $t=$quick->quick('quick_lookup',['student_no'=>'100002','room_number'=>'501'],'127.0.0.1')['task']; eq(count($t['members']),2); try { $quick->quick('quick_lookup',['student_no'=>'100001','room_number'=>'501'],'127.0.0.1'); } catch(ApiError $e) { eq($e->errorCode,'QUICK_NOT_FOUND'); return; } throw new RuntimeException('former leader accepted'); });
test('跨班转入不得绕过原班授权',function () use ($domain,$a,$classA,$head) { $csv=$head."测试甲班,100002,虚构乙,甲楼,501,100001\n"; $p=$domain->handle('import_preview',$a,['class_id'=>$classA,'csv'=>$csv]); ok(count($p['errors'])>0,'unauthorized transfer accepted'); });
test('撤销授权立即限制读取',function () use ($domain,$admin,$a) { $domain->handle('class_permissions_save',$admin,['user_id'=>$a['id'],'class_ids'=>[]]); eq($domain->handle('tasks',$a,['date'=>'2026-10-29'])['tasks'],[]); eq($domain->handle('roster',$a,[])['students'],[]); });
test('旧版迁移保留记录并停用宿舍长',function () use ($config) { $db=Database::connect($config); Schema::install($db); Schema::set($db,'class_name','旧班'); $now=Support::now(); Support::exec($db,"INSERT INTO users (student_no,name,role,status,created_at,updated_at) VALUES (?,?,?,?,?,?)",['888001','旧负责人','leader','active',$now,$now]); Support::exec($db,"INSERT INTO users (student_no,name,role,status,created_at,updated_at) VALUES (?,?,?,?,?,?)",['888002','旧班委','committee','active',$now,$now]); ok(Grade::preview($db)['ready'],'migration preview failed'); Grade::install($db); $leader=Support::one($db,"SELECT role,status FROM users WHERE student_no='888001'"); eq($leader,['role'=>'retired','status'=>'disabled']); eq((int)Support::one($db,'SELECT COUNT(*) AS n FROM class_permissions')['n'],1); Grade::install($db); eq(Schema::get($db,'schema_version'),2); });
test('年级版默认日期在八点切换且不补造旧任务',function () use ($config) { $clock=new GradeClock(); $clock->value='2026-11-01 07:59:59'; $config['clock']=$clock; $db=Database::connect($config); $domain=new Domain($db,$config); $domain->install(); $auth=new Auth($db,$config); $created=$auth->createUser('777001','测试管理员','admin'); Support::exec($db,"UPDATE users SET status='active',totp_enabled=1 WHERE id=?",[$created['user_id']]); $u=['id'=>$created['user_id'],'student_no'=>'777001','name'=>'测试管理员','role'=>'admin','reauth_until'=>time()+300]; eq($domain->handle('prepare_today',$u,[])['business_date'],'2026-10-31'); eq(Support::one($db,'SELECT COUNT(*) AS n FROM night_tasks')['n'],0); $clock->value='2026-11-01 08:00:00'; eq($domain->handle('prepare_today',$u,[])['business_date'],'2026-11-01'); eq((int)Support::one($db,'SELECT COUNT(*) AS n FROM night_tasks')['n'],1); });
test('旧库班名冲突时迁移预检安全停止',function () use ($config) { $db=Database::connect($config); Schema::install($db); Schema::set($db,'class_name','当前班'); $now=Support::now(); Support::exec($db,'INSERT INTO rooms (building,room_number) VALUES (?,?)',['甲楼','501']); Support::exec($db,'INSERT INTO students (student_no,name,class_name,room_id,leader_no,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',['123456','虚构同学','历史班',1,'123456',$now,$now]); ok(!Grade::preview($db)['ready'],'conflicting migration accepted'); try { Grade::install($db); } catch(ApiError $e) { eq(Schema::get($db,'schema_version'),1); eq((int)Support::one($db,'SELECT COUNT(*) AS n FROM students')['n'],1); return; } throw new RuntimeException('migration did not stop'); });
test('明确映射历史班名并保留冻结前的住宿历史',function () use ($config) {
    $db=Database::connect($config); Schema::install($db); Schema::set($db,'class_name','当前班');
    $at='2026-01-01 12:00:00'; Support::exec($db,'INSERT INTO rooms (building,room_number) VALUES (?,?)',['甲楼','501']);
    Support::exec($db,'INSERT INTO students (student_no,name,class_name,room_id,leader_no,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',['123456','虚构同学','历史班',1,'123456',$at,$at]);
    Support::exec($db,'INSERT INTO placements (student_id,room_id,student_no,name,class_name,leader_no,valid_from) VALUES (?,?,?,?,?,?,?)',[1,1,'123456','虚构同学','历史班','123456',$at]);
    Support::exec($db,'INSERT INTO night_tasks (business_date,check_at,due_at,created_at,updated_at) VALUES (?,?,?,?,?)',['2026-01-01','2026-01-01 22:30:00','2026-01-01 22:35:00',$at,$at]);
    $snapshot=['people'=>[['student_id'=>99,'class_name'=>'旧乙班']]];
    Support::exec($db,'INSERT INTO daily_reports (task_id,version,snapshot_json,reason,as_of,created_at) VALUES (?,?,?,?,?,?)',[1,1,Support::json($snapshot),'测试',$at,$at]);
    ok(!Grade::preview($db)['ready'],'missing mapping accepted');
    ok(!Grade::preview($db,['当前班'=>'当前班','历史班'=>'当前班'])['ready'],'snapshot alias omitted');
    $map=['当前班'=>'当前班','历史班'=>'当前班','旧乙班'=>'乙班'];
    ok(Grade::preview($db,$map)['ready'],'valid mapping refused'); Grade::install($db,$map);
    eq(Support::one($db,'SELECT class_name FROM students WHERE student_no=?',['123456'])['class_name'],'当前班');
    $history=Support::all($db,'SELECT class_name,valid_to FROM placements ORDER BY id'); eq(count($history),2); eq($history[0]['class_name'],'历史班'); ok($history[0]['valid_to']!==null,'old placement not closed');
    eq(Grade::classId($db,'历史班'),Grade::classId($db,'当前班')); ok(Grade::classId($db,'旧乙班')!==Grade::classId($db,'当前班'),'distinct classes merged');
    eq(Support::one($db,'SELECT snapshot_json FROM daily_reports WHERE id=1')['snapshot_json'],Support::json($snapshot));
});
test('年级备份恢复保留业务数据并撤销旧班级授权',function () use ($config) {
    $dir=sys_get_temp_dir().'/dorm-grade-backup-'.bin2hex(random_bytes(8));
    mkdir($dir,0700,true); mkdir($dir.'/sessions',0700,true);
    $config['storage_path']=$dir; $db=Database::connect($config); $domain=new Domain($db,$config); $domain->install();
    $auth=new Auth($db,$config); $created=$auth->createUser('777002','虚构班委','committee');
    Support::exec($db,"UPDATE users SET status='active',password_hash='synthetic',totp_enabled=1 WHERE id=?",[$created['user_id']]);
    $class=(int)Support::one($db,'SELECT id FROM classes ORDER BY id LIMIT 1')['id'];
    Support::exec($db,'INSERT INTO class_permissions (user_id,class_id,created_at) VALUES (?,?,?)',[$created['user_id'],$class,Support::now()]);
    putenv('DORM_BACKUP_KEY='.base64_encode(random_bytes(32))); $backup=new DormCheck\Backup($db,$config); $file=$dir.'/test.dcb';
    try {
        $backup->create($file); file_put_contents($dir.'/sessions/sess_synthetic','synthetic');
        Support::exec($db,"UPDATE classes SET name='备份后修改' WHERE id=?",[$class]);
        DormCheck\Backup::maintenance($config); $backup->restore($file);
        eq(Support::one($db,'SELECT name FROM classes WHERE id=?',[$class])['name'],'测试甲班');
        eq((int)Support::one($db,'SELECT COUNT(*) AS n FROM class_permissions WHERE revoked_at IS NULL')['n'],0);
        $user=Support::one($db,'SELECT status,password_hash,totp_enabled FROM users WHERE id=?',[$created['user_id']]);
        eq($user['status'],'pending'); eq($user['password_hash'],null); eq((int)$user['totp_enabled'],0);
        ok(!is_file($dir.'/sessions/sess_synthetic'),'session survived recovery');
        $maintenance=json_decode((string)file_get_contents($dir.'/maintenance.json'),true); ok($maintenance['recovered']===true,'recovery did not remain in maintenance');
    } finally {
        putenv('DORM_BACKUP_KEY');
        foreach (glob($dir.'/sessions/*') ?: [] as $entry) unlink($entry);
        foreach (glob($dir.'/maintenance/*') ?: [] as $entry) if (is_file($entry)) unlink($entry);
        if (is_dir($dir.'/maintenance')) rmdir($dir.'/maintenance');
        if (is_file($dir.'/maintenance.json')) unlink($dir.'/maintenance.json');
        if (is_file($file)) unlink($file); rmdir($dir.'/sessions'); rmdir($dir);
    }
});
test('篡改的年级备份不会覆盖当前数据',function () use ($config) {
    $dir=sys_get_temp_dir().'/dorm-grade-damaged-'.bin2hex(random_bytes(8)); mkdir($dir,0700,true);
    $config['storage_path']=$dir; $db=Database::connect($config); (new Domain($db,$config))->install();
    putenv('DORM_BACKUP_KEY='.base64_encode(random_bytes(32))); $backup=new DormCheck\Backup($db,$config); $file=$dir.'/test.dcb'; $bad=$dir.'/bad.dcb';
    try {
        $backup->create($file); $bytes=file_get_contents($file); $bytes[60]=chr(ord($bytes[60])^1); file_put_contents($bad,$bytes);
        DormCheck\Backup::maintenance($config);
        try { $backup->restore($bad); throw new RuntimeException('damaged backup was accepted'); } catch (ApiError $e) { ok(str_contains($e->getMessage(),'校验失败'),'unexpected recovery error'); }
        eq((int)Support::one($db,'SELECT COUNT(*) AS n FROM classes')['n'],1);
        $maintenance=json_decode((string)file_get_contents($dir.'/maintenance.json'),true); ok(!$maintenance['block_all'],'damaged backup changed maintenance state');
    } finally {
        putenv('DORM_BACKUP_KEY');
        foreach (glob($dir.'/maintenance/*') ?: [] as $entry) if (is_file($entry)) unlink($entry);
        if (is_dir($dir.'/maintenance')) rmdir($dir.'/maintenance');
        foreach ([$file,$bad,$dir.'/maintenance.json'] as $entry) if (is_file($entry)) unlink($entry);
        rmdir($dir);
    }
});
$failed=count(array_filter($results,fn($r)=>!$r['ok'])); echo Support::json(['passed'=>count($results)-$failed,'failed'=>$failed,'results'=>$results]).PHP_EOL; if ($failed) exit(1);
