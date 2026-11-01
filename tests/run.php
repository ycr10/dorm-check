<?php
declare(strict_types=1);

use DormCheck\ApiError;
use DormCheck\Auth;
use DormCheck\Backup;
use DormCheck\Clock;
use DormCheck\Database;
use DormCheck\Domain;
use DormCheck\Reports;
use DormCheck\Schema;
use DormCheck\Security;
use DormCheck\Support;

foreach (['Core','Schema','Grade','Auth','Xlsx','Reports','Domain','Backup'] as $file) require_once dirname(__DIR__).'/src/'.$file.'.php';
date_default_timezone_set('Asia/Shanghai');

final class FrozenClock implements Clock
{
    public function __construct(public string $value='2026-10-29 22:00:00') {}
    public function now(): string { return $this->value; }
}

final class MysqlDdlCaptured extends RuntimeException {}

final class MysqlDdlCapture extends PDO
{
    public array $statements=[];
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return 'mysql'; }
    public function exec(string $statement): int|false { $this->statements[]=$statement; return 0; }
    public function prepare(string $query, array $options=[]): PDOStatement|false { throw new MysqlDdlCaptured(); }
}

final class Fixture
{
    public PDO $db;
    public Domain $domain;
    public FrozenClock $clock;
    public array $config;
    public array $admin;
    public array $committee;
    public array $a;
    public array $b;
    public string $directory;
    public string $csv;
    public int $classA;
    public int $classB;
    public function __construct()
    {
        $this->directory=sys_get_temp_dir().'/dorm-test-'.bin2hex(random_bytes(8)); mkdir($this->directory,0700,true);
        $this->clock=new FrozenClock();
        $this->config=['env'=>'development','app_url'=>'http://127.0.0.1:8765','class_name'=>'测试班','db_dsn'=>'sqlite::memory:','db_user'=>'','db_password'=>'','app_key'=>base64_encode(random_bytes(32)),'storage_path'=>$this->directory,'idle_seconds'=>1800,'session_seconds'=>28800,'reauth_seconds'=>300,'clock'=>$this->clock];
        $this->db=Database::connect($this->config); $this->domain=new Domain($this->db,$this->config); $this->domain->install();
        $auth=new Auth($this->db,$this->config);
        $users=[];
        foreach ([['00990000','虚构管理员','admin'],['00990001','虚构年级班委','committee'],['00100001','虚构甲班委','committee'],['00100003','虚构乙班委','committee']] as [$no,$name,$role]) {
            $created=$auth->createUser($no,$name,$role);
            Support::exec($this->db,"UPDATE users SET status='active',totp_enabled=1 WHERE id=?",[$created['user_id']]);
            $users[]=['id'=>$created['user_id'],'name'=>$name,'student_no'=>$no,'role'=>$role,'reauth_until'=>time()+300];
        }
        [$this->admin,$this->committee,$this->a,$this->b]=$users;
        $this->classA=(int)Support::one($this->db,'SELECT id FROM classes WHERE name=?',['测试班'])['id'];
        $this->classB=(int)$this->call('class_create',['name'=>'测试乙班'])['class']['id'];
        $this->call('class_permissions_save',['user_id'=>$this->committee['id'],'class_ids'=>[$this->classA,$this->classB]]);
        $this->call('class_permissions_save',['user_id'=>$this->a['id'],'class_ids'=>[$this->classA]]);
        $this->call('class_permissions_save',['user_id'=>$this->b['id'],'class_ids'=>[$this->classB]]);
        $this->csv=$this->makeCsv([['00100001','虚构寝室长甲','001','00100001'],['00100002','=1+1','001','00100001'],['00100003','虚构寝室长乙','002','00100003'],['00100004','虚构同学丁','002','00100003']]);
        $this->import($this->csv);
    }
    public function makeCsv(array $rows): string
    {
        $s=fopen('php://temp','w+'); fputcsv($s,['班级','学号','姓名','楼栋','房间号','寝室长学号'],',','"','');
        foreach ($rows as [$no,$name,$room,$leader]) {
            $class=in_array($no,['00100003','00100004'],true)?'测试乙班':'测试班';
            fputcsv($s,[$class,$no,$name,'甲楼',$room,$leader],',','"','');
        }
        rewind($s); $csv=stream_get_contents($s); fclose($s); return $csv;
    }
    public function call(string $action, array $data=[], ?array $user=null): array
    {
        if (in_array($action,['import_preview','import_commit'],true) && !isset($data['class_id'])) $data['class_id']=$this->classA;
        return $this->domain->handle($action,$user ?? $this->admin,$data);
    }
    public function import(string $csv): array
    {
        $source=fopen('php://temp','w+'); fwrite($source,$csv); rewind($source); $header=fgetcsv($source,0,',','"',''); $files=[];
        while (($row=fgetcsv($source,0,',','"',''))!==false) {
            $class=$row[0] ?? ''; if (!isset($files[$class])) { $files[$class]=fopen('php://temp','w+'); fputcsv($files[$class],$header,',','"',''); }
            fputcsv($files[$class],$row,',','"','');
        }
        fclose($source); $result=[];
        foreach ($files as $name=>$stream) {
            rewind($stream); $part=stream_get_contents($stream); fclose($stream); $classId=$name==='测试乙班'?$this->classB:$this->classA;
            $p=$this->call('import_preview',['class_id'=>$classId,'csv'=>$part]); if ($p['errors']) throw new RuntimeException(Support::json($p['errors']));
            $result=$this->call('import_commit',['class_id'=>$classId,'csv'=>$part,'hash'=>$p['hash'],'confirm'=>true]);
        }
        return $result;
    }
    public function open(string $time='2026-10-29 22:30:00'): void { $this->clock->value=$time; $this->domain->tick(); }
    public function task(?array $user=null, string $date='2026-10-29'): array { return $this->call('tasks',['date'=>$date],$user ?? $this->a)['tasks'][0]; }
    public function payload(array $task, string $status='present'): array
    {
        return ['task_id'=>$task['id'],'roster_version'=>$task['roster_version'],'version'=>$task['report_version'],'idempotency_key'=>bin2hex(random_bytes(12)),'members'=>array_map(fn($m)=>['student_id'=>$m['student_id'],'status'=>$status,'location'=>in_array($status,['away','unknown'],true)?'图书馆':''],$task['members'])];
    }
    public function submit(string $status='present', ?array $user=null): array { $user=$user ?? $this->a; return $this->call('report_initial',$this->payload($this->task($user),$status),$user)['task']; }
    public function update(array $task, array $ids, string $status='present', array $extra=[], ?array $user=null): array
    {
        return $this->call('report_update',$extra+['task_id'=>$task['id'],'student_ids'=>$ids,'status'=>$status,'location'=>in_array($status,['away','unknown'],true)?'图书馆':'','version'=>$task['report_version'],'idempotency_key'=>bin2hex(random_bytes(12))],$user ?? $this->a)['task'];
    }
    public function report(): array { return $this->call('reports',['date'=>'2026-10-29']); }
    public function clean(): void
    {
        $this->db=nullDb();
        $remove=function ($dir) use (&$remove): void { foreach (scandir($dir) as $item) { if ($item==='.' || $item==='..') continue; $path=$dir.'/'.$item; if (is_dir($path) && !is_link($path)) $remove($path); else unlink($path); } rmdir($dir); };
        if (is_dir($this->directory)) $remove($this->directory);
    }
}
function nullDb(): PDO { return new PDO('sqlite::memory:'); }
function fixtureSession(Fixture $f, array $user): void
{
    $raw=Support::one($f->db,'SELECT auth_version FROM users WHERE id=?',[$user['id']]);
    $_SESSION=['uid'=>$user['id'],'auth_version'=>(int)$raw['auth_version'],'issued_at'=>time(),'last_activity'=>time(),'reauth_until'=>time()+300];
}
function totpSettingsPayload(Fixture $f, mixed $studentNo): array
{
    return ['settings'=>['totp_student_no'=>$studentNo],'totp_previous_student_no'=>Schema::get($f->db,'totp_student_no'),'confirm_totp_change'=>true];
}
function same(mixed $actual, mixed $expected, string $why=''): void { if ($actual!==$expected) throw new RuntimeException(($why!==''?$why.': ':'').'expected '.Support::json($expected).', got '.Support::json($actual)); }
function check(bool $value, string $why): void { if (!$value) throw new RuntimeException($why); }
function rejects(callable $work, string $code='VALIDATION_ERROR'): void
{
    try { $work(); } catch (ApiError $e) { same($e->errorCode,$code); return; }
    throw new RuntimeException('Expected ApiError '.$code);
}
$results=[]; $skips=[];
function test(string $name, callable $work, bool $fixture=true): void
{
    global $results; $f=null;
    try { if ($fixture) $f=new Fixture(); $work($f); $results[]=['name'=>$name,'ok'=>true]; }
    catch (Throwable $e) { $results[]=['name'=>$name,'ok'=>false,'error'=>get_class($e).': '.$e->getMessage(),'line'=>$e->getFile().':'.$e->getLine()]; }
    finally { if ($f) $f->clean(); }
}

// All fixtures are synthetic, isolated databases; no production configuration is loaded.
test('MySQL DDL generation quotes delayed (capture only, not a MySQL server test)',function (): void {
    $db=new MysqlDdlCapture();
    try { Schema::install($db); } catch (MysqlDdlCaptured) {}
    $night=array_values(array_filter($db->statements,fn($sql)=>str_starts_with($sql,'CREATE TABLE IF NOT EXISTS night_tasks ')));
    same(count($night),1);
    check(str_contains($night[0],'`delayed` INTEGER NOT NULL DEFAULT 0'),'MySQL reserved column is not quoted');
    check(str_contains($night[0],'ENGINE=InnoDB'),'MySQL DDL branch was not exercised');
},false);
test('schema installation is idempotent and identifiers keep zeroes',function (Fixture $f): void {
    $f->domain->install(); $roster=$f->call('roster'); same(count($roster['students']),4); same($roster['students'][0]['student_no'],'00100001'); same($roster['students'][0]['room'],'001');
    check(!array_key_exists('password_hash',$roster['users'][0]),'roster exposes credentials');
});
test('GET reads do not create tasks or update the write lock',function (Fixture $f): void {
    $before=$f->db->query('SELECT revision FROM app_lock')->fetchColumn(); $f->call('tasks',['date'=>'2026-11-01'],$f->a); $f->call('reports',['date'=>'2026-11-01']);
    same($f->db->query('SELECT revision FROM app_lock')->fetchColumn(),$before); same((int)$f->db->query('SELECT COUNT(*) FROM night_tasks')->fetchColumn(),1);
});
test('CSV rejects duplicate identifiers, missing leaders, wrong class and changed preview',function (Fixture $f): void {
    $bad=$f->makeCsv([['00100001','甲','001','99999999'],['00100001','乙','001','99999999']]); $p=$f->call('import_preview',['csv'=>$bad]); check(count($p['errors'])>=2,'CSV validation missed errors');
    $p=$f->call('import_preview',['csv'=>$f->csv]); rejects(fn()=> $f->call('import_commit',['csv'=>$f->csv."\n",'hash'=>$p['hash'],'confirm'=>true]),'PREVIEW_REQUIRED');
    rejects(fn()=> $f->call('import_preview',['csv'=>str_repeat('x',2*1024*1024+1)]));
    check(count($f->call('import_preview',['csv'=>str_replace('测试班','别的班',$f->csv)])['errors'])>0,'cross-class import accepted');
});
test('server rejects early initial submission and ignores client clock or role',function (Fixture $f): void {
    $p=$f->payload($f->task()); $p['now']='2026-10-29 23:30:00'; rejects(fn()=> $f->call('report_initial',$p,$f->a),'TASK_NOT_OPEN');
    $fake=$f->a; $fake['role']='admin'; $visible=$f->call('roster',[],$fake)['students']; same(count($visible),2); check(!in_array('00100003',array_column($visible,'student_no'),true),'forged role expanded class access');
    same($f->report()['metrics']['unreported'],4);
});
test('initial batch is atomic for foreign members, duplicates and unreported values',function (Fixture $f): void {
    $f->open(); $p=$f->payload($f->task()); $p['members'][1]['student_id']=$f->task($f->b)['members'][0]['student_id'];
    rejects(fn()=> $f->call('report_initial',$p,$f->a),'FORBIDDEN'); same((int)$f->db->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn(),0); same($f->task()['report_version'],0);
    $p=$f->payload($f->task()); $p['members'][1]['student_id']=$p['members'][0]['student_id']; rejects(fn()=> $f->call('report_initial',$p,$f->a));
    $p=$f->payload($f->task()); $p['members'][1]['status']='unreported'; rejects(fn()=> $f->call('report_initial',$p,$f->a)); same($f->report()['metrics']['present'],0);
});
test('initial is complete, explicit and exactly-once on identical retries',function (Fixture $f): void {
    $f->open(); $p=$f->payload($f->task()); $one=$f->call('report_initial',$p,$f->a); $two=$f->call('report_initial',$p,$f->a); same($one,$two); same((int)$f->db->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn(),2);
    $p['members'][0]['status']='unknown'; rejects(fn()=> $f->call('report_initial',$p,$f->a),'IDEMPOTENCY_CONFLICT'); same($f->task()['report_version'],1);
});
test('two authorized reporters cannot silently overwrite a version',function (Fixture $f): void {
    $f->open(); $first=$f->submit('away'); $second=$first;
    $f->call('class_permissions_save',['user_id'=>$f->b['id'],'class_ids'=>[$f->classA,$f->classB]]);
    $f->update($first,[$first['members'][0]['student_id']]); rejects(fn()=> $f->update($second,[$second['members'][1]['student_id']],'present',[],$f->b),'VERSION_CONFLICT');
    same($f->report()['metrics']['away'],1);
});
test('batch updates reject foreign members without partially saving',function (Fixture $f): void {
    $f->open(); $t=$f->submit('away'); rejects(fn()=> $f->update($t,[$t['members'][0]['student_id'],$f->task($f->b)['members'][0]['student_id']]),'FORBIDDEN'); same($f->report()['metrics']['away'],2);
});
test('class revocation applies to old sessions and cached request retries',function (Fixture $f): void {
    $f->open(); $p=$f->payload($f->task()); $f->call('report_initial',$p,$f->a);
    $f->call('class_permissions_save',['user_id'=>$f->a['id'],'class_ids'=>[]]);
    same($f->call('tasks',[],$f->a)['tasks'],[]); rejects(fn()=> $f->call('report_initial',$p,$f->a),'FORBIDDEN');
    $f->call('class_permissions_save',['user_id'=>$f->a['id'],'class_ids'=>[$f->classB]]); $f->clock->value='2026-10-30 00:00:00';
    rejects(fn()=> $f->call('report_initial',$p,$f->a),'FORBIDDEN'); same(count($f->call('tasks',['date'=>'2026-10-29'],$f->a)['tasks']),1);
});
test('new class permissions allow followup on the original night',function (Fixture $f): void {
    $f->open(); $t=$f->submit('away'); $f->call('class_permissions_save',['user_id'=>$f->a['id'],'class_ids'=>[]]);
    $f->clock->value='2026-10-30 00:20:00';
    rejects(fn()=> $f->update($t,[$t['members'][0]['student_id']],'present',[],$f->b),'FORBIDDEN');
    $f->call('class_permissions_save',['user_id'=>$f->b['id'],'class_ids'=>[$f->classA,$f->classB]]);
    $changed=$f->update($t,[$t['members'][0]['student_id']],'present',[],$f->b); same($changed['members'][0]['initial_status'],'away');
});
test('cross-midnight returns preserve original night and human/receipt times',function (Fixture $f): void {
    $f->open(); $t=$f->submit('away'); $f->clock->value='2026-10-30 00:20:00'; $t=$f->update($t,[$t['members'][0]['student_id']],'present',['occurred_at'=>'2026-10-30 00:10:00']);
    same($t['business_date'],'2026-10-29'); same($t['members'][0]['initial_status'],'away'); same($t['members'][0]['current_status'],'present'); same($t['members'][0]['return_at'],'2026-10-30 00:10:00');
    $event=$f->report()['events'][2]; same($event['recorded_at'],'2026-10-30 00:20:00'); same($event['occurred_at'],'2026-10-30 00:10:00');
});
test('late initial separates the check-time facts from current status',function (Fixture $f): void {
    $f->open('2026-10-29 23:00:00'); $p=$f->payload($f->task(),'away'); $p['members'][0]['current_status']='present'; $t=$f->call('report_initial',$p,$f->a)['task'];
    same($t['late'],true); same($t['members'][0]['initial_status'],'away'); same($t['members'][0]['current_status'],'present'); same($t['members'][0]['return_at'],'2026-10-29 23:00:00');
    same($t['members'][0]['initial_location'],'图书馆'); same($t['members'][0]['location'],''); same($t['members'][1]['location'],'图书馆');
    $events=$f->report()['events']; same($events[0]['after']['initial_location'],'图书馆'); same($events[0]['after']['location'],'');
});
test('late initial keeps independent current whereabouts and restores them from a private draft',function (Fixture $f): void {
    $f->open('2026-10-29 23:00:00'); $t=$f->task(); $p=$f->payload($t); $p['members'][0]['current_status']='away'; $p['members'][0]['current_location']='操场';
    $f->call('draft_save',['task_id'=>$t['id'],'roster_version'=>$t['roster_version'],'members'=>$p['members']],$f->a);
    $draft=$f->call('draft_get',['task_id'=>$t['id']],$f->a)['draft']; same($draft['members'][0]['current_location'],'操场');
    $t=$f->call('report_initial',$p,$f->a)['task']; same($t['members'][0]['initial_status'],'present'); same($t['members'][0]['initial_location'],''); same($t['members'][0]['current_status'],'away'); same($t['members'][0]['location'],'操场');
});
test('missing current whereabouts rejects the whole initial submission atomically',function (Fixture $f): void {
    $f->open('2026-10-29 23:00:00'); $t=$f->task(); $before=$f->report();
    foreach (['away','unknown'] as $status) {
        $p=$f->payload($t); $p['members'][1]['current_status']=$status; rejects(fn()=> $f->call('report_initial',$p,$f->a));
        same($f->task()['submitted_at'],null); same($f->task()['members'][0]['initial_status'],'unreported'); same(count($f->report()['events']),count($before['events']));
    }
});
test('reasoned corrections preserve original and current facts independently',function (Fixture $f): void {
    $f->open(); $t=$f->submit('away'); $base=['task_id'=>$t['id'],'student_id'=>$t['members'][0]['student_id'],'status'=>'present','reason'=>'核实原申报误填','target'=>'initial','version'=>$t['report_version'],'idempotency_key'=>bin2hex(random_bytes(12))];
    rejects(fn()=> $f->call('report_correct',$base,$f->b),'FORBIDDEN'); $t=$f->call('report_correct',$base)['task']; same($t['members'][0]['initial_status'],'away'); same($t['members'][0]['corrected_initial_status'],'present'); same($t['members'][0]['current_status'],'away');
    $base['reason']=''; $base['version']=$t['report_version']; $base['idempotency_key']=bin2hex(random_bytes(12)); rejects(fn()=> $f->call('report_correct',$base));
});
test('exempt is server-approved and safety contact does not imply present',function (Fixture $f): void {
    $f->open(); $p=$f->payload($f->task(),'exempt'); rejects(fn()=> $f->call('report_initial',$p,$f->a));
    $t=$f->submit('away'); $id=$t['members'][0]['student_id']; $f->call('followup_save',['task_id'=>$t['id'],'student_id'=>$id,'state'=>'safe','note'=>'电话确认安全但仍未回寝']); same($f->report()['metrics']['away'],2);
    rejects(fn()=> $f->call('exemption_save',['student_id'=>$id,'start_date'=>'2026-10-29','end_date'=>'2026-10-29','source'=>'核实许可','approved'=>true],$f->b),'FORBIDDEN');
});
test('revoked exemption changes unresolved exempt state to unknown, preserving initial',function (Fixture $f): void {
    $f->open(); $t=$f->task(); $grants=[];
    foreach ($t['members'] as $m) $grants[]=$f->call('exemption_save',['student_id'=>$m['student_id'],'start_date'=>'2026-10-29','end_date'=>'2026-10-30','source'=>'虚构已核实许可','approved'=>true])['exemption'];
    $t=$f->submit('exempt'); $f->call('exemption_revoke',['id'=>$grants[0]['id'],'reason'=>'许可取消需返寝']); $t=$f->task(); same($t['members'][0]['initial_status'],'exempt'); same($t['members'][0]['current_status'],'unknown');
    $t=$f->update($t,[$t['members'][1]['student_id']]); same($t['members'][1]['current_status'],'present'); check($t['members'][1]['frozen_exemption']===null,'late exemption must not rewrite original frozen metadata');
});
test('fixed report snapshots remain immutable while latest changes',function (Fixture $f): void {
    $f->open(); $t=$f->submit('away'); $fixed=$f->call('report_snapshot',['date'=>'2026-10-29']); $f->clock->value='2026-10-29 23:10:00'; $f->update($t,[$t['members'][0]['student_id']]);
    $again=$f->call('reports',['date'=>'2026-10-29','version'=>$fixed['snapshot_id']]); same($again['metrics']['away'],2); same($f->report()['metrics']['away'],1); same($again['as_of'],$fixed['as_of']);
    rejects(fn()=> $f->call('reports',['date'=>'2026-10-30','version'=>$fixed['snapshot_id']]),'NOT_FOUND'); rejects(fn()=> $f->call('reports',['date'=>'2026-10-29','version'=>'../config/local.php']));
});
test('six-sheet export is real OOXML, identifiers/text are never formulas',function (Fixture $f): void {
    $f->open(); $f->submit(); $export=$f->call('export',['date'=>'2026-10-29']); $zip=new ZipArchive(); same($zip->open($export['download_path']),true);
    $workbook=$zip->getFromName('xl/workbook.xml'); same(substr_count($workbook,'<sheet '),6); check(str_contains($workbook,'补报与更正记录'),'missing sixth sheet');
    $detail=$zip->getFromName('xl/worksheets/sheet2.xml'); check(str_contains($detail,'00100001'),'leading zeros lost'); check(str_contains($detail,'=1+1'),'text fixture missing'); check(!preg_match('/<f(?:\s|>)/',$detail),'formula cells emitted');
    for ($i=1;$i<=6;$i++) check(simplexml_load_string($zip->getFromName('xl/worksheets/sheet'.$i.'.xml'))!==false,'invalid worksheet XML'); $zip->close();
    $u=$f->admin; $u['reauth_until']=time()-1; rejects(fn()=> $f->call('export',['date'=>'2026-10-29'],$u),'REAUTH_REQUIRED');
    $own=$f->call('export',['date'=>'2026-10-29'],$f->a); $zip=new ZipArchive(); same($zip->open($own['download_path']),true); $detail=$zip->getFromName('xl/worksheets/sheet2.xml'); check(str_contains($detail,'00100001'),'own class omitted'); check(!str_contains($detail,'00100003'),'other class leaked'); $zip->close();
});
test('drafts are private, versioned and never count as submitted attendance',function (Fixture $f): void {
    $t=$f->task(); $p=$f->payload($t,'away'); $f->call('draft_save',['task_id'=>$t['id'],'roster_version'=>$t['roster_version'],'members'=>$p['members']],$f->a);
    check($f->call('draft_get',['task_id'=>$t['id']],$f->a)['draft']!==null,'draft not saved'); rejects(fn()=> $f->call('draft_get',['task_id'=>$t['id']],$f->b),'FORBIDDEN'); same($f->report()['metrics']['unreported'],4);
    $f->open(); $f->submit(); same($f->call('draft_get',['task_id'=>$t['id']],$f->a)['draft'],null);
});
test('frozen roster survives current-name changes and adjustment has its own version',function (Fixture $f): void {
    $f->open(); $t=$f->submit(); $fixed=$f->call('report_snapshot',['date'=>'2026-10-29']); $f->clock->value='2026-10-29 23:00:00'; $f->import(str_replace('=1+1','新名字',$f->csv));
    same($f->task()['members'][1]['name'],'=1+1');
    $adjust=['task_id'=>$t['id'],'roster_version'=>$t['roster_version'],'student_ids'=>[$t['members'][0]['student_id']],'reason'=>'核实当晚范围，多列一人']; $changed=$f->call('task_roster_adjust',$adjust)['task']; same(count($changed['members']),1); check($changed['roster_version']>$t['roster_version'],'roster version unchanged');
    rejects(fn()=> $f->call('task_roster_adjust',$adjust),'ROSTER_CONFLICT'); same($f->call('reports',['date'=>'2026-10-29','version'=>$fixed['snapshot_id']])['metrics']['total'],4);
});
test('settings are validated atomically and do not rewrite frozen task times',function (Fixture $f): void {
    $f->open(); $t=$f->task(); rejects(fn()=> $f->call('settings_save',['settings'=>['check_time'=>'23:00','due_time'=>'22:00']])); same($f->call('settings')['settings']['check_time'],'22:30');
    $f->call('settings_save',['settings'=>['check_time'=>'23:00','due_time'=>'23:05','paused_dates'=>['2026-10-30']]]); same($f->task()['check_at'],$t['check_at']); $f->clock->value='2026-10-30 23:10:00'; $f->domain->tick(); same($f->task($f->a,'2026-10-30')['phase'],'paused');
    rejects(fn()=> $f->call('settings_save',['settings'=>['class_name'=>'越权']],$f->committee),'FORBIDDEN');
});
test('unresolved rooms cannot close; reopening preserves events and increases version',function (Fixture $f): void {
    $f->open(); $t=$f->submit('away'); rejects(fn()=> $f->call('task_close',['task_id'=>$t['id'],'reason'=>'待处理']),'UNRESOLVED_MEMBERS');
    $t=$f->update($t,array_column($t['members'],'student_id')); $closed=$f->call('task_close',['task_id'=>$t['id'],'reason'=>'已逐人核实'])['task']; same($closed['phase'],'closed'); rejects(fn()=> $f->update($closed,[$closed['members'][0]['student_id']]),'TASK_CLOSED');
    $again=$f->call('task_reopen',['task_id'=>$t['id'],'reason'=>'收到新情况'])['task']; same($again['phase'],'open'); check($again['report_version']>$closed['report_version'],'reopen version unchanged');
});
test('nobody reporting still produces a complete due snapshot, once only',function (Fixture $f): void {
    $f->open('2026-10-29 22:35:00'); $f->domain->tick(); $versions=$f->report()['versions']; same(count($versions),1); $fixed=$f->call('reports',['date'=>'2026-10-29','version'=>$versions[0]['id']]); same($fixed['metrics']['unreported'],4); same($fixed['metrics']['present'],0);
});
test('missed days catch up with explicit actual timestamps and no duplicate reports',function (Fixture $f): void {
    $f->clock->value='2026-10-31 23:00:00'; $result=$f->domain->tick(); same(count($result['processed']),3); same((int)$f->db->query('SELECT COUNT(*) FROM daily_reports')->fetchColumn(),3); $f->domain->tick(); same((int)$f->db->query('SELECT COUNT(*) FROM daily_reports')->fetchColumn(),3);
    $old=$f->call('reports',['date'=>'2026-10-29']); $fixed=$f->call('reports',['date'=>'2026-10-29','version'=>$old['versions'][0]['id']]); same($fixed['as_of'],'2026-10-31 23:00:00'); check(str_contains($fixed['reason'],'延迟'),'late report not labeled');
});
test('safe and exempt arrival wording never claims everyone is physically present',function (Fixture $f): void {
    $f->open(); $f->submit(); $t=$f->task($f->b);
    foreach ($t['members'] as $m) $f->call('exemption_save',['student_id'=>$m['student_id'],'start_date'=>'2026-10-29','end_date'=>'2026-10-29','source'=>'虚构许可','approved'=>true]);
    $f->submit('exempt',$f->b); same($f->report()['arrival_summary'],'应归人员已到齐，另有 2 人获准不返寝');
});
test('session passive polling expires, password-version changes revoke sessions',function (Fixture $f): void {
    $auth=new Auth($f->db,$f->config); $version=Support::one($f->db,'SELECT auth_version FROM users WHERE id=?',[$f->a['id']])['auth_version'];
    $_SESSION=['uid'=>$f->a['id'],'auth_version'=>$version,'issued_at'=>time()-100,'last_activity'=>time()-100,'reauth_until'=>0]; $last=$_SESSION['last_activity']; check($auth->current(false)!==null,'valid session rejected'); same($_SESSION['last_activity'],$last);
    $_SESSION['last_activity']=time()-1801; same($auth->current(false),null);
    $_SESSION=['uid'=>$f->a['id'],'auth_version'=>$version,'issued_at'=>time(),'last_activity'=>time()]; Support::exec($f->db,'UPDATE users SET auth_version=auth_version+1 WHERE id=?',[$f->a['id']]); same($auth->current(),null);
});
test('login failure and token attempts are limited without disclosing existence',function (Fixture $f): void {
    $auth=new Auth($f->db,$f->config); $_SESSION=[];
    for ($i=0;$i<5;$i++) rejects(fn()=> $auth->handle('login',['student_no'=>'not-an-account','password'=>'wrong']),'LOGIN_FAILED');
    rejects(fn()=> $auth->handle('login',['student_no'=>'not-an-account','password'=>'wrong']),'RATE_LIMITED');
    rejects(fn()=> $auth->handle('activate',['student_no'=>'missing','code'=>'wrong','password'=>'valid-long-test-passphrase']),'TOKEN_INVALID');
});
test('RFC 6238 SHA1 vectors, replay protection and authenticated encryption',function (): void {
    $secret=Security::base32('12345678901234567890');
    foreach ([59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130'] as $time=>$expected) same(Security::totp($secret,(int)$time,8),$expected);
    $time=1234567890; $code=Security::totp($secret,$time); $step=Security::totpStep($secret,$code,$time); check($step!==null,'valid OTP rejected'); same(Security::totpStep($secret,$code,$time,$step),null);
    $config=['app_key'=>base64_encode(random_bytes(32))]; $encrypted=Security::encrypt('private test secret',$config); same(Security::decrypt($encrypted,$config),'private test secret');
    $raw=base64_decode($encrypted); $raw[15]=chr(ord($raw[15])^1); try { Security::decrypt(base64_encode($raw),$config); throw new LogicException('Tampered ciphertext accepted'); } catch (RuntimeException $e) { check(!$e instanceof LogicException,'Tampered ciphertext accepted'); }
},false);
test('backup restores data in maintenance and invalidates credentials, grants and sessions',function (Fixture $f): void {
    $f->open(); $t=$f->submit('away'); putenv('DORM_BACKUP_KEY='.base64_encode(random_bytes(32))); $backup=new Backup($f->db,$f->config); $file=$f->directory.'/test.dcb'; $backup->create($file);
    $f->update($t,[$t['members'][0]['student_id']]); same($f->report()['metrics']['away'],1); Backup::maintenance($f->config); $backup->restore($file);
    same(Reports::build($f->db,'2026-10-29',$f->clock->now())['metrics']['away'],2); same((int)$f->db->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn(),0); same((int)$f->db->query('SELECT COUNT(*) FROM auth_tokens')->fetchColumn(),0); same((int)$f->db->query('SELECT COUNT(*) FROM grants WHERE revoked_at IS NULL')->fetchColumn(),0);
    check(is_file($f->directory.'/maintenance.json'),'restore opened business prematurely');
});
test('backup authentication rejects tampering before replacing business data',function (Fixture $f): void {
    putenv('DORM_BACKUP_KEY='.base64_encode(random_bytes(32))); $backup=new Backup($f->db,$f->config); $file=$f->directory.'/test.dcb'; $backup->create($file); $bytes=file_get_contents($file); $bytes[60]=chr(ord($bytes[60])^1); file_put_contents($f->directory.'/damaged.dcb',$bytes); Backup::maintenance($f->config);
    rejects(fn()=> $backup->restore($f->directory.'/damaged.dcb')); same((int)$f->db->query('SELECT COUNT(*) FROM students')->fetchColumn(),4);
});
test('retention cleanup is explicit and does not regenerate deleted nights',function (Fixture $f): void {
    $f->open(); $f->submit(); $f->clock->value='2026-11-02 23:00:00'; $f->domain->tick(); $removed=$f->domain->prune('2026-11-01'); same($removed['nights'],3); $f->domain->tick(); same((int)$f->db->query("SELECT COUNT(*) FROM night_tasks WHERE business_date<'2026-11-01'")->fetchColumn(),0);
});

test('freeze refreshes approved exemptions and responsible snapshots even with unchanged members',function (Fixture $f): void {
    $t=$f->task(); $f->clock->value='2026-10-29 22:25:00'; $id=$t['members'][0]['student_id'];
    $f->call('exemption_save',['student_id'=>$id,'start_date'=>'2026-10-29','end_date'=>'2026-10-29','source'=>'冻结前核实许可','approved'=>true]); $f->open(); $t=$f->task();
    same(count($t['responsible']),0); check($t['members'][0]['frozen_exemption']!==null,'pre-freeze exemption missing'); same($t['members'][0]['frozen_exemption']['source'],'冻结前核实许可');
});
test('submission freezes without cron but detects a stale roster before accepting',function (Fixture $f): void {
    $old=$f->task(); $f->clock->value='2026-10-29 22:29:00'; $csv=$f->makeCsv([['00100005','虚构补录同学','001','00100001']]); $f->import($csv); $f->clock->value='2026-10-29 22:31:00';
    rejects(fn()=> $f->call('report_initial',$f->payload($old),$f->a),'ROSTER_CONFLICT'); $t=$f->task(); same(count($t['members']),3); $f->call('report_initial',$f->payload($t),$f->a); same($f->report()['metrics']['present'],3);
});
test('post-initial roster additions can be amended without falsifying original unreported values',function (Fixture $f): void {
    $f->open(); $t=$f->submit(); $f->clock->value='2026-10-29 22:45:00'; $f->import($f->makeCsv([['00100005','虚构漏列同学','001','00100001']])); $new=Support::one($f->db,'SELECT id FROM students WHERE student_no=?',['00100005']);
    $t=$f->call('task_roster_adjust',['task_id'=>$t['id'],'roster_version'=>$t['roster_version'],'student_ids'=>array_merge(array_column($t['members'],'student_id'),[(int)$new['id']]),'reason'=>'核实当晚漏列一人'])['task'];
    $t=$f->call('report_correct',['task_id'=>$t['id'],'student_id'=>$new['id'],'status'=>'present','reason'=>'补正漏列人员查寝时事实','target'=>'initial','version'=>$t['report_version'],'idempotency_key'=>bin2hex(random_bytes(12))])['task'];
    $m=array_values(array_filter($t['members'],fn($m)=>(int)$m['student_id']===(int)$new['id']))[0]; same($m['initial_status'],'unreported'); same($m['corrected_initial_status'],'present'); same($m['current_status'],'present');
});
test('transfers cannot overwrite original facts through a destination-room initial report',function (Fixture $f): void {
    $f->open(); $a=$f->submit('away'); $b=$f->task($f->b); $moved=$a['members'][1];
    $f->call('task_roster_adjust',['task_id'=>$a['id'],'roster_version'=>$a['roster_version'],'student_ids'=>[$a['members'][0]['student_id']],'reason'=>'核实本晚换寝，先移出原寝']);
    $b=$f->call('task_roster_adjust',['task_id'=>$b['id'],'roster_version'=>$b['roster_version'],'student_ids'=>array_merge(array_column($b['members'],'student_id'),[$moved['student_id']]),'reason'=>'核实本晚换寝，加入目标寝'])['task'];
    $bad=$f->payload($b); rejects(fn()=> $f->call('report_initial',$bad),'INITIAL_IMMUTABLE');
    $payload=$f->payload($b,'away'); $b=$f->call('report_initial',$payload)['task'];
    $m=array_values(array_filter($b['members'],fn($m)=>$m['student_id']===$moved['student_id']))[0]; same($m['initial_status'],'away');
    same((int)Support::one($f->db,"SELECT COUNT(*) AS n FROM attendance_events WHERE student_id=? AND type='initial'",[$moved['student_id']])['n'],1);
    $history=$f->call('task_history',['task_id'=>$b['id']])['events'];
    check(count(array_filter($history,fn($e)=>(int)$e['student_id']===$moved['student_id'] && $e['type']==='initial' && $e['room']==='甲楼 · 001'))===1,'transferred original history missing');
    check(count(array_filter($history,fn($e)=>(int)$e['student_id']===$a['members'][0]['student_id']))===0,'history leaked unrelated original-room member');
    $scoped=$f->call('task_history',['task_id'=>$b['id']],$f->b)['events'];
    check(count(array_filter($scoped,fn($e)=>(int)$e['student_id']===$moved['student_id']))===0,'other class history leaked');
});
test('task history rejects cross-room reads and includes the authorized member events',function (Fixture $f): void {
    $f->open(); $t=$f->submit('away'); $f->update($t,[$t['members'][0]['student_id']]); $history=$f->call('task_history',['task_id'=>$t['id']],$f->a); same(count($history['events']),3);
    rejects(fn()=> $f->call('task_history',['task_id'=>$t['id']],$f->b),'FORBIDDEN');
});
test('disabled accounts and unauthorized class grants are rejected by authoritative database state',function (Fixture $f): void {
    rejects(fn()=> $f->call('class_permissions_save',['user_id'=>$f->a['id'],'class_ids'=>[$f->classB]],$f->committee),'FORBIDDEN');
    Support::exec($f->db,"UPDATE users SET status='disabled' WHERE id=?",[$f->a['id']]); rejects(fn()=> $f->call('tasks',[],$f->a),'UNAUTHENTICATED');
});
test('real TOTP enrollment gates management and hashes every recovery code',function (Fixture $f): void {
    Support::exec($f->db,'UPDATE users SET totp_enabled=0,totp_secret=NULL WHERE id=?',[$f->admin['id']]);
    $version=Support::one($f->db,'SELECT auth_version FROM users WHERE id=?',[$f->admin['id']])['auth_version'];
    $_SESSION=['uid'=>$f->admin['id'],'auth_version'=>(int)$version,'issued_at'=>time(),'last_activity'=>time(),'reauth_until'=>0]; $auth=new Auth($f->db,$f->config);
    rejects(fn()=> $auth->requireUser(),'MFA_SETUP_REQUIRED'); $setup=$auth->handle('totp_setup',[]); $result=$auth->handle('totp_confirm',['code'=>Security::totp($setup['secret'],time())]); same(count($result['recovery_codes']),8); check($auth->requireUser()['totp_enabled'],'MFA enrollment did not unlock management');
    $stored=Support::all($f->db,'SELECT code_hash FROM recovery_codes'); foreach ($stored as $row) check(!in_array($row['code_hash'],$result['recovery_codes'],true),'plaintext recovery code stored');
    $raw=Support::one($f->db,'SELECT * FROM users WHERE id=?',[$f->admin['id']]); $recover=new ReflectionMethod(Auth::class,'recoveryFactor'); same($recover->invoke($auth,$raw,$result['recovery_codes'][0]),true); same($recover->invoke($auth,$raw,$result['recovery_codes'][0]),false);
    same($auth->current(),null); same((int)Support::one($f->db,'SELECT totp_enabled FROM users WHERE id=?',[$f->admin['id']])['totp_enabled'],0);
});

test('explicit retirement changes future membership, never frozen history or dependent leaders',function (Fixture $f): void {
    $f->open(); $t=$f->task(); rejects(fn()=> $f->call('student_retire',['student_id'=>$t['members'][0]['student_id'],'reason'=>'尚未交接']));
    $f->call('student_retire',['student_id'=>$t['members'][1]['student_id'],'reason'=>'已核实转班']); same(count($f->call('roster')['students']),3); same(count($f->task()['members']),2);
    $f->clock->value='2026-10-30 22:30:00'; $f->domain->tick(); same($f->call('reports',['date'=>'2026-10-30'])['metrics']['total'],3);
});
test('removing every member closes the empty scope without leaving phantom missing reports',function (Fixture $f): void {
    $f->open(); $t=$f->task(); $changed=$f->call('task_roster_adjust',['task_id'=>$t['id'],'roster_version'=>$t['roster_version'],'student_ids'=>[],'reason'=>'核实本晚该寝无人纳入'])['task'];
    same($changed['phase'],'closed'); same($changed['roster_empty'],true); $r=$f->report(); same($r['metrics']['rooms_total'],1); same($r['metrics']['rooms_pending'],1); same($r['metrics']['total'],2);
    $sheets=Reports::sheets($r); same(count(array_filter($sheets[3]['rows'],fn($row)=>($row[0] ?? '')==='寝室缺报')),1);
});
test('all-room closure and a later reopening update the nightly lifecycle',function (Fixture $f): void {
    $f->open(); $a=$f->submit(); $b=$f->submit('present',$f->b);
    foreach ([$a,$b] as $t) $f->call('task_close',['task_id'=>$t['id'],'reason'=>'全部核实']); same($f->report()['phase'],'closed');
    $f->call('task_reopen',['task_id'=>$a['id'],'reason'=>'核查新情况']); same($f->report()['phase'],'open');
});

test('unconfigured TOTP policy preserves legacy requirements without a migration',function (Fixture $f): void {
    $auth=new Auth($f->db,$f->config); same(Schema::get($f->db,'totp_student_no'),null);
    foreach ([$f->admin,$f->committee,$f->a] as $u) {
        $raw=Support::one($f->db,'SELECT * FROM users WHERE id=?',[$u['id']]); $public=$auth->publicUser($raw);
        same($public['totp_required'],$u['role']!=='leader'); same($public['totp_allowed'],true);
    }
    same($f->call('settings_save',['settings'=>['retention_days'=>200]])['relogin_required'],false);
    same(Schema::get($f->db,'totp_student_no'),null);
});
test('exclusive TOTP preserves the selected secret and recovery codes while revoking other bindings and all sessions',function (Fixture $f): void {
    $secret=Security::encrypt(Security::base32(random_bytes(20)),$f->config);
    Support::exec($f->db,'UPDATE users SET totp_secret=?,totp_last_step=123 WHERE id=?',[$secret,$f->admin['id']]);
    Support::exec($f->db,'UPDATE users SET totp_secret=? WHERE id=?',[$secret,$f->committee['id']]);
    foreach ([$f->admin,$f->committee] as $u) Support::exec($f->db,'INSERT INTO recovery_codes (user_id,code_hash) VALUES (?,?)',[$u['id'],hash('sha256',$u['student_no'])]);
    $codes=Support::all($f->db,'SELECT * FROM recovery_codes WHERE user_id=?',[$f->admin['id']]);
    $versions=Support::all($f->db,'SELECT id,auth_version FROM users ORDER BY id'); fixtureSession($f,$f->admin);
    $result=$f->call('settings_save',totpSettingsPayload($f,$f->admin['student_no'])); same($result['relogin_required'],true);
    $owner=Support::one($f->db,'SELECT * FROM users WHERE id=?',[$f->admin['id']]); same($owner['totp_secret'],$secret); same((int)$owner['totp_last_step'],123); same((int)$owner['totp_enabled'],1);
    same(Support::all($f->db,'SELECT * FROM recovery_codes WHERE user_id=?',[$f->admin['id']]),$codes);
    $other=Support::one($f->db,'SELECT * FROM users WHERE id=?',[$f->committee['id']]); same((int)$other['totp_enabled'],0); same($other['totp_secret'],null); same((int)$other['totp_last_step'],-1);
    same((int)$f->db->query('SELECT COUNT(*) FROM recovery_codes')->fetchColumn(),count($codes));
    foreach ($versions as $u) same((int)Support::one($f->db,'SELECT auth_version FROM users WHERE id=?',[$u['id']])['auth_version'],(int)$u['auth_version']+1);
    $auth=new Auth($f->db,$f->config); same($auth->current(),null);
    fixtureSession($f,$f->committee); same($auth->requireUser()['totp_required'],false); same(count($f->call('roster',[],$f->committee)['students']),4);
    $audit=Support::one($f->db,"SELECT detail_json FROM audit_logs WHERE action='settings.totp_account'"); check($audit!==null,'TOTP policy audit missing'); check(!str_contains($audit['detail_json'],$secret),'audit leaked secret');
});
test('TOTP account changes reject unknown, inactive, empty and non-string student numbers atomically',function (Fixture $f): void {
    $auth=new Auth($f->db,$f->config); $pending=$auth->createUser('00888888','虚构未激活账号','committee');
    Support::exec($f->db,"UPDATE users SET status='disabled' WHERE id=?",[$f->b['id']]);
    $before=Support::all($f->db,'SELECT id,auth_version,totp_enabled FROM users ORDER BY id');
    foreach (['00999999','',null,990000,[],'00888888',$f->b['student_no']] as $value) {
        $data=totpSettingsPayload($f,$value); $data['settings']['grade_name']='不应保存'; rejects(fn()=> $f->call('settings_save',$data));
        same(Schema::get($f->db,'totp_student_no'),null); same(Schema::get($f->db,'grade_name'),null); same(Support::all($f->db,'SELECT id,auth_version,totp_enabled FROM users ORDER BY id'),$before);
    }
});
test('TOTP policy requires admin, recent verification, explicit confirmation and the current previous value',function (Fixture $f): void {
    $payload=totpSettingsPayload($f,$f->admin['student_no']);
    rejects(fn()=> $f->call('settings_save',$payload,$f->committee),'FORBIDDEN'); rejects(fn()=> $f->call('settings_save',$payload,$f->a),'FORBIDDEN');
    $expired=$f->admin; $expired['reauth_until']=time()-1; rejects(fn()=> $f->call('settings_save',$payload,$expired),'REAUTH_REQUIRED');
    $missing=$payload; unset($missing['confirm_totp_change']); rejects(fn()=> $f->call('settings_save',$missing),'MFA_POLICY_CONFIRM_REQUIRED');
    $stale=$payload; $stale['totp_previous_student_no']='00999999'; rejects(fn()=> $f->call('settings_save',$stale),'MFA_POLICY_CONFLICT');
    $missing=$payload; unset($missing['totp_previous_student_no']); rejects(fn()=> $f->call('settings_save',$missing),'MFA_POLICY_CONFLICT'); same(Schema::get($f->db,'totp_student_no'),null);
    $f->call('settings_save',$payload); rejects(fn()=> $f->call('settings_save',array_replace($payload,['settings'=>['totp_student_no'=>$f->committee['student_no']]])),'MFA_POLICY_CONFLICT');
});
test('saving the same TOTP account or ordinary settings does not revoke bindings or sessions again',function (Fixture $f): void {
    $f->call('settings_save',totpSettingsPayload($f,$f->admin['student_no']));
    $before=Support::all($f->db,'SELECT id,auth_version FROM users ORDER BY id');
    same($f->call('settings_save',['settings'=>['totp_student_no'=>$f->admin['student_no']]])['relogin_required'],false);
    same($f->call('settings_save',['settings'=>['retention_days'=>201]])['relogin_required'],false);
    same(Support::all($f->db,'SELECT id,auth_version FROM users ORDER BY id'),$before); same(Schema::get($f->db,'totp_student_no'),$f->admin['student_no']);
});
test('a designated committee account must enroll while others can work without TOTP',function (Fixture $f): void {
    Support::exec($f->db,'UPDATE users SET totp_enabled=0 WHERE id=?',[$f->a['id']]);
    $f->call('settings_save',totpSettingsPayload($f,$f->a['student_no'])); $auth=new Auth($f->db,$f->config);
    fixtureSession($f,$f->a); same($auth->current()['totp_required'],true); rejects(fn()=> $auth->requireUser(),'MFA_SETUP_REQUIRED'); rejects(fn()=> $f->call('tasks',[],$f->a),'MFA_SETUP_REQUIRED');
    $setup=$auth->handle('totp_setup',[]); $bound=$auth->handle('totp_confirm',['code'=>Security::totp($setup['secret'],time())]); same(count($bound['recovery_codes']),8); same($auth->requireUser()['totp_enabled'],true); same(count($f->call('tasks',[],$f->a)['tasks']),1);
    foreach ([$f->admin,$f->committee] as $u) {
        fixtureSession($f,$u); same($auth->requireUser()['totp_required'],false); same($auth->current()['totp_allowed'],false);
        same(count($f->call('roster',[],$u)['students']),4); rejects(fn()=> $auth->handle('totp_setup',[]),'MFA_NOT_ALLOWED');
    }
});
test('pending enrollment cannot bypass a changed TOTP account policy',function (Fixture $f): void {
    Support::exec($f->db,'UPDATE users SET totp_enabled=0 WHERE id=?',[$f->a['id']]);
    $auth=new Auth($f->db,$f->config); fixtureSession($f,$f->a); $setup=$auth->handle('totp_setup',[]); $oldSession=$_SESSION;
    $f->call('settings_save',totpSettingsPayload($f,$f->admin['student_no'])); $_SESSION=$oldSession;
    rejects(fn()=> $auth->handle('totp_confirm',['code'=>Security::totp($setup['secret'],time())]),'UNAUTHENTICATED');
    fixtureSession($f,$f->a); $_SESSION['pending_totp']=$setup['secret']; rejects(fn()=> $auth->handle('totp_confirm',['code'=>Security::totp($setup['secret'],time())]),'MFA_NOT_ALLOWED');
    same((int)Support::one($f->db,'SELECT totp_enabled FROM users WHERE id=?',[$f->a['id']])['totp_enabled'],0);
});
test('failed TOTP confirmations retain rate limits after the enrollment transaction rolls back',function (Fixture $f): void {
    Support::exec($f->db,'UPDATE users SET totp_enabled=0 WHERE id=?',[$f->admin['id']]); fixtureSession($f,$f->admin); $auth=new Auth($f->db,$f->config); $auth->handle('totp_setup',[]);
    for ($i=0;$i<5;$i++) rejects(fn()=> $auth->handle('totp_confirm',['code'=>'']),'OTP_INVALID');
    rejects(fn()=> $auth->handle('totp_confirm',['code'=>'']),'RATE_LIMITED'); same((int)$f->db->query('SELECT COUNT(*) FROM recovery_codes')->fetchColumn(),0);
});
test('switching the designated TOTP account clears the former owner and requires the new owner to enroll',function (Fixture $f): void {
    $f->call('settings_save',totpSettingsPayload($f,$f->admin['student_no']));
    $changed=$f->call('settings_save',totpSettingsPayload($f,$f->committee['student_no'])); same($changed['relogin_required'],true);
    $auth=new Auth($f->db,$f->config); fixtureSession($f,$f->admin); same($auth->requireUser()['totp_required'],false); same($auth->current()['totp_enabled'],false);
    fixtureSession($f,$f->committee); rejects(fn()=> $auth->requireUser(),'MFA_SETUP_REQUIRED');
    $setup=$auth->handle('totp_setup',[]); $auth->handle('totp_confirm',['code'=>Security::totp($setup['secret'],time())]); same($auth->requireUser()['totp_enabled'],true);
});
test('selected-account recovery still requires reenrollment under the exclusive policy',function (Fixture $f): void {
    Support::exec($f->db,'UPDATE users SET totp_enabled=0 WHERE id=?',[$f->a['id']]);
    $f->call('settings_save',totpSettingsPayload($f,$f->a['student_no'])); fixtureSession($f,$f->a); $auth=new Auth($f->db,$f->config);
    $setup=$auth->handle('totp_setup',[]); $bound=$auth->handle('totp_confirm',['code'=>Security::totp($setup['secret'],time())]); $raw=Support::one($f->db,'SELECT * FROM users WHERE id=?',[$f->a['id']]);
    $recover=new ReflectionMethod(Auth::class,'recoveryFactor'); same($recover->invoke($auth,$raw,$bound['recovery_codes'][0]),true); same($recover->invoke($auth,$raw,$bound['recovery_codes'][0]),false); same($auth->current(),null);
    fixtureSession($f,$f->a); rejects(fn()=> $auth->requireUser(),'MFA_SETUP_REQUIRED'); same($auth->current()['totp_allowed'],true);
});
test('TOTP policy changes roll back the setting, credentials and session versions on database failure',function (Fixture $f): void {
    $before=Support::all($f->db,'SELECT id,auth_version,totp_enabled FROM users ORDER BY id');
    $f->db->exec("CREATE TRIGGER reject_totp_clear BEFORE UPDATE OF totp_enabled ON users BEGIN SELECT RAISE(ABORT,'synthetic policy failure'); END");
    $failed=false; try { $f->call('settings_save',totpSettingsPayload($f,$f->admin['student_no'])); } catch (PDOException) { $failed=true; }
    check($failed,'synthetic database failure did not occur'); same(Schema::get($f->db,'totp_student_no'),null); same(Support::all($f->db,'SELECT id,auth_version,totp_enabled FROM users ORDER BY id'),$before);
});

test('quick lookup uses the dorm roster without a login account and does not expose other rooms or private records',function (Fixture $f): void {
    $f->open(); Support::exec($f->db,'DELETE FROM users WHERE id=?',[$f->a['id']]);
    $r=$f->domain->quick('quick_lookup',['student_no'=>'00100001','room_number'=>'001'],'192.0.2.1');
    same($r['task']['business_date'],'2026-10-29'); same(count($r['task']['members']),2);
    same(array_column($r['task']['members'],'name'),['虚构寝室长甲','=1+1']);
    check(!str_contains(Support::json($r),'00100003'),'other room leaked');
    check(!array_key_exists('student_no',$r['task']['members'][0]),'member student number leaked');
    check(!array_key_exists('exemption_json',$r['task']['members'][0]),'exemption details leaked');
    rejects(fn()=> $f->domain->quick('quick_lookup',['student_no'=>'00100001','room_number'=>'002'],'192.0.2.2'),'QUICK_NOT_FOUND');
    rejects(fn()=> $f->domain->quick('quick_lookup',['student_no'=>'00100002','room_number'=>'001'],'192.0.2.3'),'QUICK_NOT_FOUND');
    rejects(fn()=> $f->domain->quick('quick_lookup',['student_no'=>'00100001','room_number'=>'1'],'192.0.2.4'),'QUICK_NOT_FOUND');
});
test('quick initial is atomic, records unverified identity, and replays the same submission safely',function (Fixture $f): void {
    $f->open(); $identity=['student_no'=>'00100001','room_number'=>'001']; $t=$f->domain->quick('quick_lookup',$identity,'192.0.2.1')['task'];
    $members=array_map(fn($m)=>['student_id'=>$m['student_id'],'status'=>'present','location'=>''],$t['members']);
    $p=$identity+['version'=>$t['version'],'roster_version'=>$t['roster_version'],'idempotency_key'=>'quick-test-000001','members'=>$members];
    $bad=$p; $bad['members'][1]['status']='away'; rejects(fn()=> $f->domain->quick('quick_initial',$bad,'192.0.2.1'));
    same((int)$f->db->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn(),0);
    $r=$f->domain->quick('quick_initial',$p,'192.0.2.1'); same(count($r['task']['members']),2); same($f->domain->quick('quick_initial',$p,'192.0.2.1'),$r);
    $changed=$p; $changed['members'][0]['status']='unknown'; rejects(fn()=> $f->domain->quick('quick_initial',$changed,'192.0.2.1'),'IDEMPOTENCY_CONFLICT');
    $events=Support::all($f->db,'SELECT actor_id,actor_name FROM attendance_events'); same(count($events),2); same($events[0]['actor_id'],null); same($events[0]['actor_name'],'便捷入口（未验证身份）');
    $audit=json_decode(Support::one($f->db,"SELECT detail_json FROM audit_logs WHERE action='quick_initial'")['detail_json'],true); same($audit['identity_verified'],false); same($audit['claimed_student_no'],'00100001');
});
test('quick updates preserve initial facts, reject cross-room members, stale versions and forged task or time fields',function (Fixture $f): void {
    $f->open(); $identity=['student_no'=>'00100001','room_number'=>'001']; $t=$f->domain->quick('quick_lookup',$identity,'192.0.2.1')['task'];
    $members=array_map(fn($m)=>['student_id'=>$m['student_id'],'status'=>'away','location'=>'图书馆'],$t['members']);
    $initial=$f->domain->quick('quick_initial',$identity+['version'=>$t['version'],'roster_version'=>$t['roster_version'],'idempotency_key'=>'quick-initial-01','members'=>$members],'192.0.2.1')['task'];
    $payload=$identity+['version'=>$initial['version'],'student_ids'=>[$initial['members'][0]['student_id']],'status'=>'present','location'=>'','idempotency_key'=>'quick-update-001'];
    $forged=$payload; $forged['now']='2099-01-01 23:00:00'; rejects(fn()=> $f->domain->quick('quick_update',$forged,'192.0.2.1'));
    $other=$f->task($f->b)['members'][0]['student_id']; $cross=$payload; $cross['student_ids'][]=$other; rejects(fn()=> $f->domain->quick('quick_update',$cross,'192.0.2.1'),'FORBIDDEN');
    $updated=$f->domain->quick('quick_update',$payload,'192.0.2.1')['task']; same($updated['members'][0]['initial_status'],'away'); same($updated['members'][0]['current_status'],'present');
    $stale=$payload; $stale['idempotency_key']='quick-update-002'; rejects(fn()=> $f->domain->quick('quick_update',$stale,'192.0.2.1'),'VERSION_CONFLICT');
});
test('quick entry honors check time, frozen roster version, retirement and overnight date',function (Fixture $f): void {
    $id=['student_no'=>'00100001','room_number'=>'001']; $t=$f->domain->quick('quick_lookup',$id,'192.0.2.1')['task'];
    $p=$id+['version'=>$t['version'],'roster_version'=>$t['roster_version'],'idempotency_key'=>'quick-time-00001','members'=>array_map(fn($m)=>['student_id'=>$m['student_id'],'status'=>'present'],$t['members'])];
    rejects(fn()=> $f->domain->quick('quick_initial',$p,'192.0.2.1'),'TASK_NOT_OPEN');
    $f->open(); $p['roster_version']=-1; rejects(fn()=> $f->domain->quick('quick_initial',$p,'192.0.2.1'),'ROSTER_CONFLICT');
    $f->clock->value='2026-10-30 00:10:00'; same($f->domain->quick('quick_lookup',$id,'192.0.2.1')['task']['business_date'],'2026-10-29');
    Support::exec($f->db,'UPDATE students SET active=0 WHERE student_no=?',[$id['student_no']]); rejects(fn()=> $f->domain->quick('quick_lookup',$id,'192.0.2.1'),'QUICK_NOT_FOUND');
});
test('quick lookup limits repeated invalid requests without logging raw IP',function (Fixture $f): void {
    $bad=['student_no'=>'99999999','room_number'=>'999'];
    for ($i=0;$i<60;$i++) rejects(fn()=> $f->domain->quick('quick_lookup',$bad,'192.0.2.199'),'QUICK_NOT_FOUND');
    rejects(fn()=> $f->domain->quick('quick_lookup',$bad,'192.0.2.199'),'RATE_LIMITED');
    check(!str_contains(Support::json(Support::all($f->db,'SELECT * FROM rate_limits')),'192.0.2.199'),'raw client IP stored');
});
test('quick before 08:00 never creates a missing previous-night task',function (Fixture $f): void {
    $f->clock->value='2026-10-30 07:59:59'; $f->db->exec('DELETE FROM night_tasks');
    rejects(fn()=> $f->domain->quick('quick_lookup',['student_no'=>'00100001','room_number'=>'001'],'192.0.2.1'),'QUICK_NOT_FOUND');
    same((int)$f->db->query('SELECT COUNT(*) FROM night_tasks')->fetchColumn(),0);
});

test('quick entry freezes an existing previous-night task if scheduling was missed',function (Fixture $f): void {
    $task=Support::one($f->db,'SELECT frozen_at FROM night_tasks WHERE business_date=?',['2026-10-29']); same($task['frozen_at'],null);
    $f->clock->value='2026-10-30 00:10:00';
    $task=$f->domain->quick('quick_lookup',['student_no'=>'00100001','room_number'=>'001'],'192.0.2.1')['task'];
    same($task['business_date'],'2026-10-29'); same($task['phase'],'open'); same(count($task['members']),2);
    check(Support::one($f->db,'SELECT frozen_at FROM night_tasks WHERE business_date=?',['2026-10-29'])['frozen_at']!==null,'existing overnight task was not frozen');
});

test('next day prepares the existing roster without a scheduled tick or another import',function (Fixture $f): void {
    $f->open(); $before=$f->task(); $f->submit();
    $f->clock->value='2026-10-30 21:00:00';
    same(count($f->call('tasks',['date'=>'2026-10-30'],$f->a)['tasks']),0);
    same($f->call('prepare_today',[],$f->a)['business_date'],'2026-10-30');
    $today=$f->task($f->a,'2026-10-30'); same(count($today['members']),2); same($today['phase'],'draft');
    same(array_column($today['members'],'student_no'),array_column($before['members'],'student_no'));
    same(count($f->call('roster')['students']),4);
    $f->clock->value='2026-10-30 22:31:00'; $f->call('prepare_today',[],$f->a);
    $today=$f->task($f->a,'2026-10-30'); same($today['phase'],'open'); check($today['frozen_at']!==null,'new night not frozen');
    $f->call('report_initial',$f->payload($today),$f->a);
    same($f->task($f->a,'2026-10-29')['members'][0]['initial_status'],'present');
    same((int)$f->db->query('SELECT COUNT(*) FROM daily_reports')->fetchColumn(),0,'browser must not replace the scheduled report');
});
test('preparing today cannot forge dates and preserves pauses, history and authorized access',function (Fixture $f): void {
    rejects(fn()=> $f->call('prepare_today',['date'=>'2026-11-05'],$f->a));
    same((int)$f->db->query('SELECT COUNT(*) FROM night_tasks')->fetchColumn(),1);
    $f->call('settings_save',['settings'=>['paused_dates'=>['2026-10-30']]]);
    $f->clock->value='2026-10-30 23:00:00'; $f->call('prepare_today',[],$f->a);
    $t=$f->task($f->a,'2026-10-30'); same($t['phase'],'paused'); same($t['frozen_at'],null);
    rejects(fn()=> $f->call('report_initial',$f->payload($t),$f->a),'TASK_NOT_OPEN');
    same(count($f->call('tasks',['date'=>'2026-10-30'],$f->b)['tasks'][0]['members']),2);
});
test('filing configuration is empty by default, public-only, validated and admin controlled',function (Fixture $f): void {
    same(Domain::publicSettings($f->db),['filing_icp'=>'','filing_police'=>'']);
    $values=['filing_icp'=>'测试ICP备00000000号-1','filing_police'=>'测试公网安备00000000000000号'];
    rejects(fn()=> $f->call('settings_save',['settings'=>$values],$f->committee),'FORBIDDEN');
    $expired=$f->admin; $expired['reauth_until']=time()-1; rejects(fn()=> $f->call('settings_save',['settings'=>$values],$expired),'REAUTH_REQUIRED');
    rejects(fn()=> $f->call('settings_save',['settings'=>['filing_icp'=>'not saved','filing_police'=>'javascript:alert(1)']]));
    same(Domain::publicSettings($f->db),['filing_icp'=>'','filing_police'=>'']);
    $nightBefore=Support::one($f->db,'SELECT check_at,due_at,phase,updated_at FROM night_tasks WHERE business_date=?',['2026-10-29']);
    $f->clock->value='2026-10-29 22:01:00';
    $f->call('settings_save',['settings'=>$values]); same(Domain::publicSettings($f->db),$values);
    same(Support::one($f->db,'SELECT check_at,due_at,phase,updated_at FROM night_tasks WHERE business_date=?',['2026-10-29']),$nightBefore,'filing changes must not rewrite unfinished task');
    same($f->call('settings')['settings']['filing_police'],$values['filing_police']);
    $f->call('settings_save',['settings'=>['filing_icp'=>'','filing_police'=>'']]);
    same(Domain::publicSettings($f->db),['filing_icp'=>'','filing_police'=>'']);
});

test('business date changes at 08:00 for quick entry and authenticated default views',function (Fixture $f): void {
    $f->open(); $f->submit(); $id=['student_no'=>'00100001','room_number'=>'001'];
    foreach (['2026-10-30 00:00:00','2026-10-30 07:59:59'] as $time) {
        $f->clock->value=$time;
        same($f->call('prepare_today',[],$f->a)['business_date'],'2026-10-29');
        same($f->call('tasks',[],$f->admin)['business_date'],'2026-10-29');
        same($f->call('dashboard')['business_date'],'2026-10-29');
        same($f->call('reports')['business_date'],'2026-10-29');
        same($f->domain->quick('quick_lookup',$id,'192.0.2.20')['task']['business_date'],'2026-10-29');
        same((int)$f->db->query("SELECT COUNT(*) FROM night_tasks WHERE business_date='2026-10-30'")->fetchColumn(),0);
    }
    $f->clock->value='2026-10-30 08:00:00';
    same($f->call('prepare_today',[],$f->a)['business_date'],'2026-10-30');
    same($f->call('tasks',[],$f->admin)['business_date'],'2026-10-30');
    same($f->call('dashboard')['business_date'],'2026-10-30');
    same($f->call('reports')['business_date'],'2026-10-30');
    same($f->domain->quick('quick_lookup',$id,'192.0.2.20')['task']['business_date'],'2026-10-30');
    same($f->call('dashboard',['date'=>'2026-10-29'])['metrics']['present'],2);
});
test('morning lookup honors daytime check time without requiring yesterday',function (Fixture $f): void {
    $f->call('settings_save',['settings'=>['reminder_time'=>'10:40','check_time'=>'10:42','due_time'=>'10:47']]);
    $f->clock->value='2026-10-30 10:43:00';
    $t=$f->domain->quick('quick_lookup',['student_no'=>'00100001','room_number'=>'001'],'192.0.2.21')['task'];
    same($t['business_date'],'2026-10-30'); same($t['phase'],'open'); same(count($t['members']),2);
    same($f->call('dashboard')['business_date'],'2026-10-30');
});
test('default preparation before eight never invents a missing historical night',function (Fixture $f): void {
    $f->clock->value='2026-10-29 07:59:59';
    same($f->call('prepare_today',[],$f->admin)['business_date'],'2026-10-28');
    same((int)$f->db->query("SELECT COUNT(*) FROM night_tasks WHERE business_date='2026-10-28'")->fetchColumn(),0);
    same($f->call('tasks',[],$f->admin)['tasks'],[]);
    rejects(fn()=> $f->domain->quick('quick_lookup',['student_no'=>'00100001','room_number'=>'001'],'192.0.2.22'),'QUICK_NOT_FOUND');
});
test('date filters return only that day even when older tasks remain open',function (Fixture $f): void {
    $f->open(); $f->clock->value='2026-10-30 21:00:00'; $f->call('prepare_today',[],$f->admin);
    foreach (['2026-10-29','2026-10-30'] as $date) {
        $result=$f->call('tasks',['date'=>$date],$f->admin); same(count($result['tasks']),2); same($result['open_tasks'],[]);
        foreach ($result['tasks'] as $task) same($task['business_date'],$date);
        foreach ($f->call('dashboard',['date'=>$date])['tasks'] as $task) same($task['business_date'],$date);
    }
    $empty=$f->call('tasks',['date'=>'2026-10-31'],$f->admin); same($empty['tasks'],[]); same($empty['open_tasks'],[]);
    same(count($f->call('tasks',['date'=>'2026-10-29'],$f->a)['tasks']),1);
});

test('scheduler still creates each calendar day independently of the 08:00 viewing cutoff',function (Fixture $f): void {
    $f->open(); $f->clock->value='2026-10-30 00:20:00'; $f->domain->tick();
    check(Support::one($f->db,'SELECT id FROM night_tasks WHERE business_date=?',['2026-10-30'])!==null,'tick did not prepare the new calendar day');
    same($f->call('dashboard')['business_date'],'2026-10-29');
    $f->clock->value='2026-10-30 08:00:00'; same($f->call('dashboard')['business_date'],'2026-10-30');
});

test('business date handles year and leap-day boundaries without backfilling',function (Fixture $f): void {
    foreach ([['2027-01-01 00:00:00','2026-12-31'],['2028-03-01 07:59:59','2028-02-29'],['2028-03-01 08:00:00','2028-03-01']] as [$now,$expected]) {
        $f->clock->value=$now; same($f->call('tasks',[],$f->admin)['business_date'],$expected);
    }
    same((int)$f->db->query('SELECT COUNT(*) FROM night_tasks')->fetchColumn(),1);
});

test('followups, inspections and snapshots use the same default date and explicit history stays separate',function (Fixture $f): void {
    $f->open(); $old=$f->task(); $student=$old['members'][0]['student_id'];
    $f->call('followup_save',['task_id'=>$old['id'],'student_id'=>$student,'state'=>'contacted','note'=>'第一天跟进']);
    $f->call('inspection_save',['task_id'=>$old['id'],'result'=>'核实','note'=>'第一天抽查']);
    $f->clock->value='2026-10-30 07:59:59';
    same($f->call('followups')['items'][0]['note'],'第一天跟进');
    same($f->call('inspections')['items'][0]['note'],'第一天抽查');
    same($f->call('report_snapshot',[])['business_date'],'2026-10-29');
    $f->clock->value='2026-10-30 22:30:00'; $f->call('prepare_today',[],$f->admin); $new=$f->task($f->a,'2026-10-30');
    $f->call('followup_save',['task_id'=>$new['id'],'student_id'=>$student,'state'=>'waiting','note'=>'第二天跟进']);
    $f->call('inspection_save',['task_id'=>$new['id'],'result'=>'核实','note'=>'第二天抽查']);
    same($f->call('followups')['items'][0]['note'],'第二天跟进');
    same($f->call('inspections')['items'][0]['note'],'第二天抽查');
    same($f->call('followups',['date'=>'2026-10-29'])['items'][0]['note'],'第一天跟进');
    same($f->call('inspections',['date'=>'2026-10-29'])['items'][0]['note'],'第一天抽查');
    same($f->call('report_snapshot',['date'=>'2026-10-29'])['business_date'],'2026-10-29');
});

if (defined('PASSWORD_ARGON2ID') && in_array('argon2id',password_algos(),true)) {
    test('real Argon2id login requires TOTP only for the designated account and grants other managers password-based recent verification',function (Fixture $f): void {
        $auth=new Auth($f->db,$f->config); $password='A private policy test passphrase!'; $secret=Security::base32(random_bytes(20));
        foreach ([$f->admin,$f->committee] as $u) Support::exec($f->db,'UPDATE users SET password_hash=? WHERE id=?',[Security::password($password,$u['student_no']),$u['id']]);
        Support::exec($f->db,'UPDATE users SET totp_secret=?,totp_last_step=-1 WHERE id=?',[Security::encrypt($secret,$f->config),$f->admin['id']]);
        $f->call('settings_save',totpSettingsPayload($f,$f->admin['student_no'])); $_SESSION=[];
        same($auth->handle('login',['student_no'=>$f->admin['student_no'],'password'=>$password])['needs_totp'],true); same($auth->current(),null);
        $owner=$auth->handle('login',['student_no'=>$f->admin['student_no'],'password'=>$password,'otp'=>Security::totp($secret,time())]); same($owner['user']['totp_required'],true);
        $auth->handle('logout',[]); $other=$auth->handle('login',['student_no'=>$f->committee['student_no'],'password'=>$password]);
        same($other['user']['totp_required'],false); same($other['user']['totp_enabled'],false); check($other['user']['reauth_until']>time(),'password-only manager login lacks recent verification');
    });
    test('real Argon2id activation, login, reset and single-use credentials',function (Fixture $f): void {
        $auth=new Auth($f->db,$f->config); $new=$auth->createUser('00888888','虚构待激活人','committee'); $_SESSION=[];
        $password='A synthetic private passphrase 2026!'; $auth->handle('activate',['student_no'=>'00888888','code'=>$new['activation_code'],'password'=>$password]);
        rejects(fn()=> $auth->handle('activate',['student_no'=>'00888888','code'=>$new['activation_code'],'password'=>$password]),'TOKEN_INVALID');
        $logged=$auth->handle('login',['student_no'=>'00888888','password'=>$password]); same($logged['user']['student_no'],'00888888'); check($auth->current()!==null,'login session missing');
        $reset=$auth->issueToken($new['user_id'],'reset'); same($auth->current(),null); $auth->handle('reset_password',['student_no'=>'00888888','code'=>$reset['code'],'password'=>'Another private synthetic passphrase!']);
        rejects(fn()=> $auth->handle('login',['student_no'=>'00888888','password'=>$password]),'LOGIN_FAILED');
    });
} else $skips[]='Argon2id activation/login/reset: runtime has no Argon2id; no password algorithm downgrade or successful-login stub was used.';

$failed=array_values(array_filter($results,fn($r)=>!$r['ok']));
echo Support::json(['php'=>PHP_VERSION,'sapi'=>PHP_SAPI,'passed'=>count($results)-count($failed),'failed'=>count($failed),'skipped'=>$skips,'results'=>$results]).PHP_EOL;
exit($failed?1:0);
