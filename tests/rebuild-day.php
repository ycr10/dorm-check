<?php
declare(strict_types=1);

use DormCheck\ApiError;
use DormCheck\Auth;
use DormCheck\Backup;
use DormCheck\Clock;
use DormCheck\Database;
use DormCheck\DayRebuilder;
use DormCheck\Domain;
use DormCheck\Reports;
use DormCheck\Schema;
use DormCheck\Support;

foreach (['Core','Schema','Grade','Auth','Xlsx','Reports','Domain','Backup','DayRebuilder'] as $name) require_once dirname(__DIR__).'/src/'.$name.'.php';
date_default_timezone_set('Asia/Shanghai');

final class RebuildClock implements Clock
{
    public string $value='2026-10-28 20:00:00';
    public ?PDO $db=null;
    public bool $failOnWrite=false;
    public int $baseline=0;
    public function now(): string
    {
        if ($this->failOnWrite && $this->db && $this->db->inTransaction()) {
            $revision=(int)Support::one($this->db,'SELECT revision FROM app_lock WHERE id=1')['revision'];
            if ($revision>$this->baseline) {
                $this->failOnWrite=false;
                $this->db->exec("CREATE TRIGGER synthetic_room_failure BEFORE INSERT ON room_tasks BEGIN SELECT RAISE(ABORT,'synthetic failure'); END");
            }
        }
        return $this->value;
    }
}

function sameValue(mixed $actual,mixed $expected,string $message=''): void
{
    if ($actual!==$expected) throw new RuntimeException(($message?$message.': ':'').'expected '.Support::json($expected).', got '.Support::json($actual));
}
function verify(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
function fails(callable $work, ?string $code=null): void
{
    try { $work(); } catch (ApiError $e) { if ($code!==null) sameValue($e->errorCode,$code); return; }
    throw new RuntimeException('Expected controlled failure');
}

final class RebuildFixture
{
    public string $dir;
    public array $config;
    public PDO $db;
    public Domain $domain;
    public DayRebuilder $service;
    public RebuildClock $clock;
    public array $admin;
    public array $payloads=[];
    public array $quickSnapshots=[];
    public int $classA;
    public int $classB;

    public function __construct(bool $seed=true)
    {
        $this->dir=sys_get_temp_dir().'/dorm-rebuild-test-'.bin2hex(random_bytes(8));
        mkdir($this->dir,0700,true); mkdir($this->dir.'/backups',0700,true); mkdir($this->dir.'/sessions',0700,true);
        $this->clock=new RebuildClock();
        $this->config=['env'=>'development','app_url'=>'http://127.0.0.1:8765','class_name'=>'虚构甲班','db_dsn'=>'sqlite::memory:','db_user'=>'','db_password'=>'','app_key'=>base64_encode(random_bytes(32)),'storage_path'=>$this->dir,'idle_seconds'=>1800,'session_seconds'=>28800,'reauth_seconds'=>300,'clock'=>$this->clock];
        $this->db=Database::connect($this->config); $this->clock->db=$this->db;
        $this->domain=new Domain($this->db,$this->config); $this->domain->install();
        $auth=new Auth($this->db,$this->config); $account=$auth->createUser('900000','虚构管理员','admin');
        Support::exec($this->db,"UPDATE users SET status='active',totp_enabled=1 WHERE id=?",[$account['user_id']]);
        $this->admin=['id'=>$account['user_id'],'name'=>'虚构管理员','student_no'=>'900000','role'=>'admin','reauth_until'=>time()+300];
        $this->classA=(int)Support::one($this->db,'SELECT id FROM classes WHERE name=?',['虚构甲班'])['id'];
        $this->classB=(int)$this->call('class_create',['name'=>'虚构乙班'])['class']['id'];
        if ($seed) {
            $header="班级,学号,姓名,楼栋,房间号,寝室长学号\n";
            $this->import($this->classA,$header."虚构甲班,100001,虚构甲,甲楼,501,100001\n虚构甲班,100003,虚构丙,甲楼,502,100003\n");
            $this->import($this->classB,$header."虚构乙班,100002,虚构乙,甲楼,501,100001\n");
            foreach (['2026-10-28','2026-10-29','2026-10-30'] as $date) $this->seedNight($date);
        }
        $this->clock->value='2026-10-29 22:40:00';
        $this->service=new DayRebuilder($this->db,$this->config);
    }
    public function call(string $action,array $data=[]): array { return $this->domain->handle($action,$this->admin,$data); }
    public function import(int $class,string $csv): void
    {
        $p=$this->call('import_preview',['class_id'=>$class,'csv'=>$csv]);
        if ($p['errors']) throw new RuntimeException(Support::json($p['errors']));
        $this->call('import_commit',['class_id'=>$class,'csv'=>$csv,'hash'=>$p['hash'],'confirm'=>true]);
    }
    public function seedNight(string $date): void
    {
        $this->clock->value=$date.' 22:31:00'; $this->domain->prepare($date);
        $task=$this->call('tasks',['date'=>$date])['tasks'][0];
        $payload=['task_id'=>$task['id'],'version'=>$task['report_version'],'roster_version'=>$task['roster_version'],'idempotency_key'=>'initial-'.$date.'-0001','members'=>array_map(fn($m)=>['student_id'=>$m['student_id'],'status'=>'present','location'=>''],$task['members'])];
        $initial=$this->call('report_initial',$payload); $this->payloads[$date]=['payload'=>$payload,'result'=>$initial];
        $identity=['student_no'=>'100001','room_number'=>'501'];
        $quick=$this->domain->quick('quick_lookup',$identity,'192.0.2.1')['task'];
        $this->domain->quick('quick_update',$identity+['version'=>$quick['version'],'student_ids'=>[$quick['members'][0]['student_id']],'status'=>'away','location'=>'虚构地点','idempotency_key'=>'quick-'.$date.'-0001'],'192.0.2.1');
        $this->quickSnapshots[$date]=$this->domain->quick('quick_lookup',$identity,'192.0.2.1')['task'];
        $this->call('followup_save',['task_id'=>$task['id'],'student_id'=>$task['members'][0]['student_id'],'state'=>'contacted','note'=>'虚构跟进']);
        $this->call('inspection_save',['task_id'=>$task['id'],'result'=>'一致','note'=>'虚构抽查']);
        $this->call('report_snapshot',['date'=>$date,'reason'=>'虚构固定日报']);
        Support::exec($this->db,'INSERT INTO drafts (task_id,room_id,actor_id,roster_version,content_json,updated_at) VALUES (?,?,?,?,?,?)',[$task['task_id'],$task['room_id'],$this->admin['id'],$task['roster_version'],'[]',$this->clock->value]);
        Support::exec($this->db,'INSERT INTO grants (user_id,room_id,valid_from,task_id,revoked_at,actor_id,created_at) VALUES (?,?,?,?,?,?,?)',[$this->admin['id'],$task['room_id'],$date.' 00:00:00',$task['task_id'],$date.' 23:59:00',$this->admin['id'],$this->clock->value]);
    }
    public function maintenance(): void { Backup::maintenance($this->config); }
    public function key(): void { putenv('DORM_BACKUP_KEY='.base64_encode(str_repeat('a',32))); }
    public function backupPath(string $name='before.dcb'): string { return $this->dir.'/backups/'.$name; }
    public function rebuild(string $date='2026-10-29',string $name='before.dcb'): array { return $this->service->rebuild($date,'RESET-'.$date,$this->backupPath($name),true); }
    public function table(string $table,string $where='',array $params=[]): array { return Support::all($this->db,'SELECT * FROM '.$table.($where?' WHERE '.$where:'').' ORDER BY 1,2',$params); }
    public function clean(): void
    {
        putenv('DORM_BACKUP_KEY'); $this->db=new PDO('sqlite::memory:'); $this->clock->db=null;
        $remove=function ($dir) use (&$remove): void { foreach (scandir($dir) as $item) { if ($item==='.' || $item==='..') continue; $path=$dir.'/'.$item; if (is_dir($path) && !is_link($path)) $remove($path); else unlink($path); } rmdir($dir); };
        if (is_dir($this->dir)) $remove($this->dir);
    }
}

$results=[];
function testRebuild(string $name,callable $work,bool $seed=true): void
{
    global $results; $f=null;
    try { $f=new RebuildFixture($seed); $work($f); $results[]=['name'=>$name,'ok'=>true]; }
    catch (Throwable $e) { $results[]=['name'=>$name,'ok'=>false,'error'=>get_class($e).': '.$e->getMessage(),'line'=>$e->getFile().':'.$e->getLine()]; }
    finally { if ($f) $f->clean(); }
}

testRebuild('参数必须显式指定模式、日期、确认及停机声明',function (RebuildFixture $f): void {
    sameValue(DayRebuilder::arguments(['--mode=preview','--date=2026-10-29']),['mode'=>'preview','date'=>'2026-10-29']);
    fails(fn()=>DayRebuilder::arguments(['--date=2026-10-29']));
    fails(fn()=>DayRebuilder::arguments(['--mode=preview','--date=2026-10-29','--backup=/tmp/a.dcb']));
    fails(fn()=>DayRebuilder::arguments(['--mode=execute','--date=2026-10-29','--confirm=RESET-2026-10-29','--backup=/tmp/a.dcb']));
    fails(fn()=>DayRebuilder::arguments(['--mode=preview','--date=2026-10-29','--date=2026-10-30']));
});

testRebuild('必须处于正常维护模式，预览不写入审计或重建业务',function (RebuildFixture $f): void {
    $before=$f->table('night_tasks'); fails(fn()=> $f->service->preview('2026-10-29')); sameValue($f->table('night_tasks'),$before);
    $f->maintenance(); $audit=$f->table('audit_logs'); $revision=Support::one($f->db,'SELECT revision FROM app_lock WHERE id=1')['revision'];
    $preview=$f->service->preview('2026-10-29'); verify($preview['can_execute'],'valid roster rejected'); sameValue($preview['counts']['night_tasks'],1); verify($preview['counts']['request_keys']>=2,'target keys not counted');
    sameValue($f->table('night_tasks'),$before); sameValue($f->table('audit_logs'),$audit); sameValue(Support::one($f->db,'SELECT revision FROM app_lock WHERE id=1')['revision'],$revision);
    Backup::maintenance($f->config,true); fails(fn()=> $f->service->preview('2026-10-29'));
    Backup::maintenance($f->config,false,true); fails(fn()=> $f->service->preview('2026-10-29'));
    file_put_contents($f->dir.'/maintenance.json','{invalid'); fails(fn()=> $f->service->preview('2026-10-29'));
});

testRebuild('日期与执行确认必须精确匹配',function (RebuildFixture $f): void {
    $f->maintenance(); $f->key();
    foreach (['2026-02-29','2026-10-2','2026-13-01','today',''] as $date) fails(fn()=> $f->service->preview($date));
    fails(fn()=> $f->service->rebuild('2026-10-29','RESET-2026-10-28',$f->backupPath(),true));
    fails(fn()=> $f->service->rebuild('2026-10-29','RESET-2026-10-29',$f->backupPath(),false));
    verify(!file_exists($f->backupPath()),'backup created before confirmation');
});

testRebuild('备份路径和密钥不可缺失、覆盖或进入公开目录',function (RebuildFixture $f): void {
    $f->maintenance(); putenv('DORM_BACKUP_KEY');
    fails(fn()=> $f->service->rebuild('2026-10-29','RESET-2026-10-29',$f->backupPath(),true));
    verify(!file_exists($f->backupPath()),'backup created without key'); $f->key();
    fails(fn()=> $f->service->rebuild('2026-10-29','RESET-2026-10-29','relative.dcb',true));
    fails(fn()=> $f->service->rebuild('2026-10-29','RESET-2026-10-29',dirname(__DIR__).'/public/exposed.dcb',true));
    file_put_contents($f->backupPath(),'keep'); fails(fn()=> $f->rebuild()); sameValue(file_get_contents($f->backupPath()),'keep');
});

testRebuild('缺少有效名册或数据库结构版本不符时不执行',function (RebuildFixture $f): void {
    $f->maintenance(); $f->key();
    Support::exec($f->db,'UPDATE students SET active=0');
    $p=$f->service->preview('2026-10-29'); sameValue($p['can_execute'],false);
    fails(fn()=> $f->rebuild()); verify(!file_exists($f->backupPath()),'unsafe empty backup created');
    Schema::set($f->db,'schema_version',1); fails(fn()=> $f->service->preview('2026-10-29'));
});

testRebuild('异常去重缓存阻止不确定范围的清理',function (RebuildFixture $f): void {
    $f->maintenance(); $f->key(); $before=$f->table('night_tasks');
    Support::exec($f->db,'INSERT INTO request_keys (actor_id,action,token,request_hash,result_json,created_at) VALUES (?,?,?,?,?,?)',[77,'report_update','corrupted-cache-0001',str_repeat('a',64),'{invalid','2026-10-29 22:00:00']);
    fails(fn()=> $f->service->preview('2026-10-29')); fails(fn()=> $f->rebuild()); sameValue($f->table('night_tasks'),$before); verify(!file_exists($f->backupPath()),'ambiguous cache was backed up and acted upon');
});

testRebuild('仅重建目标日期并保留其他日期、名单、账号及旧授权',function (RebuildFixture $f): void {
    $f->maintenance(); $f->key(); $old=Support::one($f->db,'SELECT id FROM night_tasks WHERE business_date=?',['2026-10-29']); $id=(int)$old['id'];
    $other=Support::all($f->db,'SELECT id FROM night_tasks WHERE business_date<>? ORDER BY id',['2026-10-29']); $others=array_map(fn($r)=>(int)$r['id'],$other);
    $before=[];
    foreach (['night_tasks','room_tasks','night_members','attendance_events','followups','inspections','daily_reports','drafts'] as $table) {
        $column=$table==='night_tasks'?'id':'task_id'; $before[$table]=[];
        foreach ($others as $otherId) $before[$table][$otherId]=$f->table($table,$column.'=?',[$otherId]);
    }
    foreach (['users','students','classes','class_names','class_permissions','rooms','placements','exemptions','job_runs'] as $table) $before[$table]=$f->table($table);
    $grants=$f->table('grants','task_id IS NULL OR task_id<>?',[$id]); $oldGrant=Support::one($f->db,'SELECT * FROM grants WHERE task_id=?',[$id]);
    $cursor=Schema::get($f->db,'scheduler_cursor'); $oldQuick=$f->quickSnapshots['2026-10-29'];
    $preview=$f->service->preview('2026-10-29'); $out=$f->rebuild(); verify(is_file($out['backup']['file']),'backup missing');
    verify($out['new_task_id']!==$id,'task ID reused'); sameValue($out['removed'],$preview['counts']); sameValue($out['members'],3); sameValue($out['rooms'],2);
    foreach (['night_tasks','room_tasks','night_members','attendance_events','followups','inspections','daily_reports','drafts'] as $table) {
        $column=$table==='night_tasks'?'id':'task_id'; foreach ($others as $otherId) sameValue($f->table($table,$column.'=?',[$otherId]),$before[$table][$otherId],$table.' changed other date');
    }
    foreach (['users','students','classes','class_names','class_permissions','rooms','placements','exemptions','job_runs'] as $table) sameValue($f->table($table),$before[$table],$table.' changed');
    sameValue($f->table('grants','task_id IS NULL OR task_id<>?',[$out['new_task_id']]),$grants);
    $newGrant=Support::one($f->db,'SELECT * FROM grants WHERE id=?',[$oldGrant['id']]); $oldGrant['task_id']=$out['new_task_id']; sameValue($newGrant,$oldGrant);
    sameValue(Schema::get($f->db,'scheduler_cursor'),$cursor);
    sameValue((int)Support::one($f->db,'SELECT COUNT(*) AS n FROM attendance_events WHERE task_id=?',[$out['new_task_id']])['n'],0);
    sameValue((int)Support::one($f->db,'SELECT COUNT(*) AS n FROM daily_reports WHERE task_id=?',[$out['new_task_id']])['n'],0);
    $now=$f->domain->quick('quick_lookup',['student_no'=>'100001','room_number'=>'501'],'192.0.2.1')['task'];
    verify($now['version']>$oldQuick['version'] && $now['roster_version']>$oldQuick['roster_version'],'old versions not fenced');
    $stale=['student_no'=>'100001','room_number'=>'501','version'=>$oldQuick['version'],'student_ids'=>[$oldQuick['members'][0]['student_id']],'status'=>'present','location'=>'','idempotency_key'=>'quick-2026-10-29-0001'];
    fails(fn()=> $f->domain->quick('quick_update',$stale,'192.0.2.1'),'VERSION_CONFLICT');
    fails(fn()=> $f->call('report_initial',$f->payloads['2026-10-29']['payload']),'FORBIDDEN');
    sameValue($f->call('report_initial',$f->payloads['2026-10-28']['payload']),$f->payloads['2026-10-28']['result'],'other date retry changed');
    verify(Support::one($f->db,"SELECT id FROM audit_logs WHERE action='maintenance.rebuild_day'")!==null,'rebuild audit missing');
});

testRebuild('冻结前名单再次变化时仍保留旧表单版本屏障',function (RebuildFixture $f): void {
    $f->maintenance(); $f->key(); $old=$f->quickSnapshots['2026-10-29'];
    $f->clock->value='2026-10-29 20:00:00'; $out=$f->rebuild(); sameValue($out['phase'],'draft');
    $floor=$out['report_version_floor']; $f->clock->value='2026-10-29 20:01:00';
    $csv="班级,学号,姓名,楼栋,房间号,寝室长学号\n虚构甲班,100004,虚构新同学,甲楼,501,100001\n"; $f->import($f->classA,$csv);
    $new=Support::one($f->db,'SELECT report_version,roster_version FROM room_tasks WHERE task_id=? AND room_id=?',[$out['new_task_id'],1]);
    sameValue((int)$new['report_version'],$floor); verify((int)$new['roster_version']>$old['roster_version'],'roster fence lost during refresh');
    $f->clock->value='2026-10-29 22:31:00'; $f->domain->prepare('2026-10-29');
    $stale=['student_no'=>'100001','room_number'=>'501','version'=>$old['version'],'student_ids'=>[$old['members'][0]['student_id']],'status'=>'present','location'=>'','idempotency_key'=>'quick-2026-10-29-0001'];
    fails(fn()=> $f->domain->quick('quick_update',$stale,'192.0.2.1'),'VERSION_CONFLICT');
});

testRebuild('中途数据库错误回滚业务数据并保留备份和维护状态',function (RebuildFixture $f): void {
    $f->maintenance(); $f->key(); $before=[];
    foreach (['night_tasks','room_tasks','night_members','attendance_events','followups','inspections','daily_reports','drafts','request_keys','grants'] as $table) $before[$table]=$f->table($table);
    $f->clock->baseline=(int)Support::one($f->db,'SELECT revision FROM app_lock WHERE id=1')['revision']; $f->clock->failOnWrite=true;
    fails(fn()=> $f->rebuild(),'REBUILD_FAILED');
    foreach ($before as $table=>$rows) sameValue($f->table($table),$rows,$table.' did not roll back');
    verify(is_file($f->backupPath()),'backup was not retained'); verify(is_file($f->dir.'/maintenance.json'),'maintenance was removed');
});

testRebuild('暂停日保留暂停；历史日期提示使用当前名单',function (RebuildFixture $f): void {
    $f->maintenance(); $f->key(); Schema::set($f->db,'paused_dates',['2026-10-29']);
    $f->clock->value='2026-10-30 12:00:00'; $preview=$f->service->preview('2026-10-29');
    verify($preview['rules']['paused'],'pause missing'); verify((bool)array_filter($preview['warnings'],fn($w)=>str_contains($w,'历史日期')),'historical warning missing');
    $out=$f->rebuild(); sameValue($out['phase'],'paused'); sameValue($out['frozen_at'],null);
});

testRebuild('目标日期原本无任务时只创建该日期',function (RebuildFixture $f): void {
    $f->maintenance(); $f->key(); $before=$f->table('night_tasks'); $date='2026-10-31';
    $preview=$f->service->preview($date); sameValue($preview['old_task_id'],null); $out=$f->rebuild($date); sameValue($out['old_task_id'],null);
    $after=$f->table('night_tasks','business_date<>?',[$date]); sameValue($after,$before); sameValue($out['members'],3);
});

testRebuild('生成的备份可在独立数据库恢复目标日期数据',function (RebuildFixture $f): void {
    $f->maintenance(); $f->key(); $oldTask=(int)Support::one($f->db,'SELECT id FROM night_tasks WHERE business_date=?',['2026-10-29'])['id'];
    $oldCount=(int)Support::one($f->db,'SELECT COUNT(*) AS n FROM attendance_events WHERE task_id=?',[$oldTask])['n']; $out=$f->rebuild();
    $copyDir=$f->dir.'/isolated'; mkdir($copyDir,0700,true); mkdir($copyDir.'/sessions',0700,true); $copyConfig=$f->config; $copyConfig['storage_path']=$copyDir;
    $copy=Database::connect($copyConfig); (new Domain($copy,$copyConfig))->install(); Backup::maintenance($copyConfig);
    (new Backup($copy,$copyConfig))->restore($out['backup']['file']);
    $restored=Support::one($copy,'SELECT id FROM night_tasks WHERE business_date=?',['2026-10-29']); sameValue((int)$restored['id'],$oldTask);
    sameValue((int)Support::one($copy,'SELECT COUNT(*) AS n FROM attendance_events WHERE task_id=?',[$oldTask])['n'],$oldCount);
    sameValue((int)Support::one($copy,"SELECT COUNT(*) AS n FROM users WHERE status='active'")['n'],0);
});

$failed=count(array_filter($results,fn($r)=>!$r['ok'])); echo Support::json(['passed'=>count($results)-$failed,'failed'=>$failed,'results'=>$results]).PHP_EOL; if ($failed) exit(1);
