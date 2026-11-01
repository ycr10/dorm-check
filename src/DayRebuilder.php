<?php
declare(strict_types=1);
namespace DormCheck;

use PDO;

/** Maintenance-only, date-scoped task rebuilding. Never accepts an HTTP request. */
final class DayRebuilder
{
    private const CHILD_TABLES = ['attendance_events','followups','inspections','drafts','daily_reports','night_members','room_tasks'];
    private const TRANSACTION_TABLES = ['app_lock','settings','audit_logs','students','placements','rooms','exemptions','grants','night_tasks','room_tasks','night_members','attendance_events','request_keys','followups','inspections','daily_reports','drafts'];
    private const WRITE_TABLES = ['app_lock','settings','audit_logs','grants','night_tasks','room_tasks','night_members','attendance_events','request_keys','followups','inspections','daily_reports','drafts'];
    private const VERSION_LIMIT = 2147483640;

    public function __construct(private PDO $db, private array $config) {}

    public static function arguments(array $arguments): array
    {
        $args=[];
        foreach ($arguments as $argument) {
            if (!is_string($argument) || !preg_match('/^--([a-z-]+)=(.*)$/sD',$argument,$match) || isset($args[$match[1]])) throw new ApiError('参数请使用不重复的 --名称=值 格式');
            $args[$match[1]]=$match[2];
        }
        $mode=$args['mode'] ?? '';
        if (!in_array($mode,['preview','execute'],true) || empty($args['date'])) throw new ApiError('请明确指定 --mode=preview 或 --mode=execute 及 --date=YYYY-MM-DD');
        $allowed=$mode==='preview'?['mode','date']:['mode','date','confirm','backup','quiesced'];
        foreach ($args as $key=>$value) if (!in_array($key,$allowed,true)) throw new ApiError('不支持的参数：--'.$key);
        if ($mode==='execute' && (empty($args['confirm']) || empty($args['backup']) || ($args['quiesced'] ?? '')!=='YES')) throw new ApiError('执行需要 --confirm、--backup 和 --quiesced=YES');
        return $args;
    }

    private function now(): string { return Support::now($this->config); }

    private function date(string $date): string
    {
        $value=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Asia/Shanghai'));
        if (!$value || $value->format('Y-m-d')!==$date) throw new ApiError('必须明确指定有效的 YYYY-MM-DD 日期');
        return $date;
    }

    private function maintenance(): void
    {
        $file=$this->config['storage_path'].'/maintenance.json';
        if (!is_file($file) || is_link($file)) throw new ApiError('请先启用本站维护模式');
        try { $marker=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiError('维护状态文件无效，请先核实'); }
        if (!is_array($marker) || !isset($marker['block_all'],$marker['recovered']) || !is_bool($marker['block_all']) || !is_bool($marker['recovered']) || $marker['block_all'] || $marker['recovered']) {
            throw new ApiError('备份恢复全阻断或待复核期间不能重建任务');
        }
    }

    private function databaseSafety(): void
    {
        if ((int)Schema::get($this->db,'schema_version',0)!==2) throw new ApiError('仅支持已迁移完成的年级版数据库结构版本 2');
        $driver=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver==='mysql') {
            $rows=Support::all($this->db,"SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'");
            $engines=[]; foreach ($rows as $row) $engines[(string)$row['TABLE_NAME']]=strtoupper((string)$row['ENGINE']);
            foreach (self::TRANSACTION_TABLES as $table) if (($engines[$table] ?? '')!=='INNODB') throw new ApiError('相关数据表不存在或不是 InnoDB，已拒绝重建');
            $triggers=Support::all($this->db,'SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()');
            foreach ($triggers as $trigger) if (in_array($trigger['EVENT_OBJECT_TABLE'],self::WRITE_TABLES,true)) throw new ApiError('相关表存在自定义触发器，请先在隔离副本核实');
        } elseif ($driver==='sqlite') {
            $triggers=Support::all($this->db,"SELECT tbl_name FROM sqlite_master WHERE type='trigger'");
            foreach ($triggers as $trigger) if (in_array($trigger['tbl_name'],self::WRITE_TABLES,true)) throw new ApiError('相关表存在自定义触发器，请先在隔离副本核实');
        } else throw new ApiError('仅支持 SQLite 和 MySQL');
    }

    private function rules(string $date): array
    {
        $check=Schema::get($this->db,'check_time','22:30'); $due=Schema::get($this->db,'due_time','22:35');
        $paused=Schema::get($this->db,'paused_dates',[]);
        if (!is_string($check) || !is_string($due) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$check) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$due) || $check>$due || !is_array($paused) || !array_is_list($paused)) throw new ApiError('当前查寝规则无效，请先核实');
        return ['check_time'=>$check,'due_time'=>$due,'paused'=>in_array($date,$paused,true)];
    }

    private function roster(string $now): array
    {
        $students=Support::all($this->db,'SELECT id,student_no,name,class_name,room_id,leader_no FROM students WHERE active=1 ORDER BY id');
        $placements=Support::all($this->db,'SELECT p.*,s.active AS student_active,r.id AS existing_room FROM placements p LEFT JOIN students s ON s.id=p.student_id LEFT JOIN rooms r ON r.id=p.room_id WHERE p.valid_from<=? AND (p.valid_to IS NULL OR p.valid_to>?) ORDER BY p.student_id,p.id',[$now,$now]);
        $byId=[]; foreach ($students as $student) $byId[(int)$student['id']]=$student;
        $seen=[]; $issues=0;
        foreach ($placements as $placement) {
            $id=(int)$placement['student_id'];
            if (isset($seen[$id]) || !isset($byId[$id]) || (int)$placement['student_active']!==1 || $placement['existing_room']===null) { $issues++; continue; }
            $seen[$id]=true; $student=$byId[$id];
            foreach (['student_no','name','class_name','leader_no'] as $field) if ($student[$field]!==$placement[$field]) { $issues++; break; }
            if ((int)$student['room_id']!==(int)$placement['room_id']) $issues++;
        }
        $issues+=count(array_diff(array_keys($byId),array_keys($seen)));
        return ['active_students'=>count($students),'effective_placements'=>count($placements),'inconsistencies'=>$issues];
    }

    private function nonNegative(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]{0,14})$/D',(string)$value)) throw new ApiError('旧提交缓存格式不完整，请先核实，未删除任何记录');
        return (int)$value;
    }

    /** Return private keys only to the caller; never print them. */
    private function cache(string $date, ?int $oldId): array
    {
        $last=null; $target=[]; $referenced=[]; $maxReport=0; $maxRoster=0;
        do {
            $where="(action IN ('report_initial','report_update','report_correct') OR action LIKE 'quick%')"; $params=[];
            if ($last!==null) {
                $where.=' AND (actor_id>? OR (actor_id=? AND action>?) OR (actor_id=? AND action=? AND token>?))';
                $params=[$last['actor_id'],$last['actor_id'],$last['action'],$last['actor_id'],$last['action'],$last['token']];
            }
            $batch=Support::all($this->db,'SELECT actor_id,action,token,result_json FROM request_keys WHERE '.$where.' ORDER BY actor_id,action,token LIMIT 100',$params);
            foreach ($batch as $row) {
                $last=$row; $action=(string)$row['action']; $quick=str_starts_with($action,'quick_initial') || str_starts_with($action,'quick_update');
                if (!$quick && !in_array($action,['report_initial','report_update','report_correct'],true)) continue;
                if ($quick && !preg_match('/^quick_(?:initial|update):([1-9][0-9]{0,14}):([1-9][0-9]{0,14})$/D',$action,$matches)) throw new ApiError('旧快捷提交缓存格式不完整，请先核实');
                try { $payload=json_decode($row['result_json'],true,512,JSON_THROW_ON_ERROR); }
                catch (\JsonException) { throw new ApiError('旧提交缓存无法解析，请先核实，未删除任何记录'); }
                if (!is_array($payload)) throw new ApiError('旧提交缓存格式不完整，请先核实');
                $task=$quick?($payload['task'] ?? null):($payload['result']['task'] ?? $payload['task'] ?? null);
                if (!is_array($task) || !is_string($task['business_date'] ?? null)) throw new ApiError('旧提交缓存缺少日期，请先核实');
                $cachedDate=$this->date($task['business_date']);
                if ($quick) $taskId=(int)$matches[1];
                else {
                    $fromField=isset($task['task_id'])?$this->nonNegative($task['task_id']):null; $fromId=null;
                    if (isset($task['id'])) {
                        if (!is_string($task['id']) || !preg_match('/^([1-9][0-9]{0,14})\.([1-9][0-9]{0,14})$/D',$task['id'],$idParts)) throw new ApiError('旧提交缓存缺少完整任务编号，请先核实');
                        $fromId=(int)$idParts[1];
                    }
                    if (($fromField===null && $fromId===null) || $fromField===0 || ($fromField!==null && $fromId!==null && $fromField!==$fromId)) throw new ApiError('旧提交缓存任务编号不一致，请先核实');
                    $taskId=$fromField ?? $fromId;
                }
                $referenced[$taskId]=true;
                if ($oldId!==null && $taskId===$oldId && $cachedDate!==$date) throw new ApiError('旧提交缓存日期与任务编号不一致，请先核实');
                if ($cachedDate!==$date) continue;
                $target[]=['actor_id'=>$row['actor_id'],'action'=>$action,'token'=>$row['token']];
                $version=$quick?($task['version'] ?? null):($task['report_version'] ?? null);
                $maxReport=max($maxReport,$this->nonNegative($version));
                $maxRoster=max($maxRoster,$this->nonNegative($task['roster_version'] ?? null));
            }
        } while (count($batch)===100);
        return ['keys'=>$target,'referenced'=>$referenced,'max_report'=>$maxReport,'max_roster'=>$maxRoster];
    }

    private function inspect(string $date): array
    {
        $now=$this->now(); $night=Support::one($this->db,'SELECT * FROM night_tasks WHERE business_date=?',[$date]);
        $oldId=$night?(int)$night['id']:null; $counts=['night_tasks'=>$night?1:0];
        foreach (self::CHILD_TABLES as $table) $counts[$table]=$oldId===null?0:(int)Support::one($this->db,"SELECT COUNT(*) AS n FROM $table WHERE task_id=?",[$oldId])['n'];
        $counts['task_specific_grants']=$oldId===null?0:(int)Support::one($this->db,'SELECT COUNT(*) AS n FROM grants WHERE task_id=?',[$oldId])['n'];
        $cache=$this->cache($date,$oldId); $counts['request_keys']=count($cache['keys']);
        $roster=$this->roster($now); $rules=$this->rules($date); $today=substr($now,0,10);
        $warnings=['本操作会清除所选日期的登记、跟进、草稿及日报；审计日志、其他日期和账号名单保留。','请先暂停本站请求和计划任务并排空在途请求；维护模式本身不终止已开始的请求。'];
        if ($date<$today) $warnings[]='历史日期将使用重建时的当前有效名单和当前规则，不代表当晚原始事实。';
        if ($date>$today) $warnings[]='未来日期使用当前有效名单；冻结前名单仍可能变化。';
        if ($rules['paused']) $warnings[]='该日期在暂停列表中，重建后仍为暂停状态。';
        if (!$roster['active_students']) $warnings[]='当前没有有效学生，不允许重建空任务。';
        if ($roster['inconsistencies']) $warnings[]='当前名单与有效住宿关系不一致，请先核实。';
        return ['public'=>['date'=>$date,'server_time'=>$now,'driver'=>$this->db->getAttribute(PDO::ATTR_DRIVER_NAME),'schema_version'=>2,'old_task_id'=>$oldId,'counts'=>$counts,'roster'=>$roster,'rules'=>$rules,'can_execute'=>$roster['active_students']>0 && $roster['inconsistencies']===0,'warnings'=>$warnings], 'night'=>$night,'cache'=>$cache];
    }

    public function preview(string $date): array
    {
        $date=$this->date($date); $this->maintenance(); $this->databaseSafety();
        return Schema::transaction($this->db,fn()=> $this->inspect($date)['public'],false);
    }

    private function backupPath(string $path): string
    {
        if (!preg_match('~^(?:/|[A-Za-z]:[\\\\/]|\\\\\\\\)~',$path) || !str_ends_with(strtolower($path),'.dcb')) throw new ApiError('备份必须使用绝对路径及 .dcb 扩展名');
        $parent=realpath(dirname($path)); $public=realpath(dirname(__DIR__).'/public');
        if ($parent===false || !is_dir($parent) || $public===false) throw new ApiError('备份父目录或应用公开目录无法核实');
        $target=$parent.DIRECTORY_SEPARATOR.basename($path); $p=$parent; $web=$public;
        if (PHP_OS_FAMILY==='Windows') { $p=strtolower($p); $web=strtolower($web); }
        if ($p===$web || str_starts_with($p,$web.DIRECTORY_SEPARATOR)) throw new ApiError('备份不能保存在本应用公开目录');
        if (file_exists($target) || is_link($target)) throw new ApiError('备份文件已存在，不能覆盖');
        return $target;
    }

    public function rebuild(string $date, string $confirmation, string $backupPath, bool $quiesced): array
    {
        $date=$this->date($date);
        if ($confirmation!=='RESET-'.$date || !$quiesced) throw new ApiError('请先确认日期，并确认已暂停本站请求及计划任务');
        $this->maintenance(); $this->databaseSafety(); $target=$this->backupPath($backupPath);
        $before=$this->preview($date);
        if (!$before['can_execute']) throw new ApiError('当前有效名单为空或住宿关系不一致，未开始备份或重建');
        $backup=(new Backup($this->db,$this->config))->create($target);
        try {
            $result=Schema::transaction($this->db,function () use ($date,$backup): array {
                $this->maintenance(); $this->databaseSafety(); $state=$this->inspect($date); $summary=$state['public'];
                if (!$summary['can_execute']) throw new ApiError('当前有效名单为空或住宿关系不一致');
                $night=$state['night']; $oldId=$night?(int)$night['id']:null;
                $global=Support::one($this->db,'SELECT COALESCE(MAX(report_version),0) AS report_max,COALESCE(MAX(roster_version),0) AS roster_max FROM room_tasks');
                $nightMax=Support::one($this->db,'SELECT COALESCE(MAX(roster_version),0) AS roster_max FROM night_tasks');
                $revision=(int)Support::one($this->db,'SELECT revision FROM app_lock WHERE id=1')['revision'];
                $reportBase=max($revision,(int)$global['report_max'],$state['cache']['max_report']);
                $rosterBase=max($revision,(int)$global['roster_max'],(int)$nightMax['roster_max'],$state['cache']['max_roster']);
                if ($reportBase>=self::VERSION_LIMIT || $rosterBase>=self::VERSION_LIMIT) throw new ApiError('任务版本接近上限，请联系维护人员处理');
                $reportFloor=$reportBase+1; $rosterSeed=$rosterBase+1;
                if ($oldId!==null) foreach (self::CHILD_TABLES as $table) {
                    $deleted=Support::exec($this->db,"DELETE FROM $table WHERE task_id=?",[$oldId]);
                    if ($deleted!==$summary['counts'][$table]) throw new ApiError('目标日期记录发生变化，请重新预览');
                }
                foreach ($state['cache']['keys'] as $key) if (Support::exec($this->db,'DELETE FROM request_keys WHERE actor_id=? AND action=? AND token=?',[$key['actor_id'],$key['action'],$key['token']])!==1) throw new ApiError('提交缓存发生变化，请重新预览');
                if ($oldId!==null && Support::exec($this->db,'DELETE FROM night_tasks WHERE id=? AND business_date=?',[$oldId,$date])!==1) throw new ApiError('目标日期任务发生变化，请重新预览');
                $now=$this->now(); $rules=$summary['rules']; $phase=$rules['paused']?'paused':'draft';
                Support::exec($this->db,'INSERT INTO night_tasks (business_date,check_at,due_at,phase,roster_version,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',[$date,$date.' '.$rules['check_time'].':00',$date.' '.$rules['due_time'].':00',$phase,$rosterSeed,$now,$now]);
                $newId=(int)$this->db->lastInsertId();
                if ($newId<=0 || $newId===$oldId || isset($state['cache']['referenced'][$newId]) || Support::one($this->db,'SELECT id FROM grants WHERE task_id=? LIMIT 1',[$newId])) throw new ApiError('新任务编号与旧记录冲突，已回滚');
                if ($oldId!==null) {
                    $moved=Support::exec($this->db,'UPDATE grants SET task_id=? WHERE task_id=?',[$newId,$oldId]);
                    if ($moved!==$summary['counts']['task_specific_grants']) throw new ApiError('目标任务授权发生变化，请重新预览');
                }
                $floorKey='rebuild_report_floor:'.$newId;
                if (Schema::get($this->db,$floorKey)!==null) throw new ApiError('新任务版本标记已存在，已回滚');
                Schema::set($this->db,$floorKey,$reportFloor);
                (new Domain($this->db,$this->config))->prepare($date);
                $new=Support::one($this->db,'SELECT id,phase,roster_version,frozen_at,`delayed` FROM night_tasks WHERE id=?',[$newId]);
                $members=(int)Support::one($this->db,'SELECT COUNT(*) AS n FROM night_members WHERE task_id=? AND included=1',[$newId])['n'];
                $rooms=(int)Support::one($this->db,'SELECT COUNT(*) AS n FROM room_tasks WHERE task_id=?',[$newId])['n'];
                if (!$new || $members!==$summary['roster']['effective_placements']) throw new ApiError('重建后名册核验失败，已回滚');
                $this->maintenance();
                Audit::write($this->db,null,'maintenance.rebuild_day',['date'=>$date,'old_task_id'=>$oldId,'new_task_id'=>$newId,'removed'=>$summary['counts'],'backup_sha256'=>$backup['sha256'],'report_floor'=>$reportFloor,'roster_version'=>(int)$new['roster_version']]);
                return ['date'=>$date,'old_task_id'=>$oldId,'new_task_id'=>$newId,'removed'=>$summary['counts'],'members'=>$members,'rooms'=>$rooms,'phase'=>$new['phase'],'frozen_at'=>$new['frozen_at'],'delayed'=>(bool)$new['delayed'],'report_version_floor'=>$reportFloor,'roster_version'=>(int)$new['roster_version']];
            });
        } catch (\Throwable $e) {
            throw new ApiError('重建未确认成功；请保持维护模式并核查数据库状态。加密备份保留在 '.$target.'。原因：'.($e instanceof ApiError?$e->getMessage():'数据库或任务生成错误'), 'REBUILD_FAILED',500);
        }
        return $result+['backup'=>['file'=>$backup['file'],'sha256'=>$backup['sha256'],'bytes'=>$backup['bytes']],'maintenance'=>true];
    }
}
