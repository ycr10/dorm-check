<?php
declare(strict_types=1);
namespace DormCheck;

use PDO;

final class Domain
{
    private const READS = ['tasks','task_history','dashboard','reports','followups','inspections','roster','settings','audit','draft_get','classes'];
    private const STATUSES = ['present','away','exempt','unknown'];
    private ?array $scope = null;
    private ?array $scopeNames = null;

    public function __construct(private PDO $db, private array $config) {}

    private function now(): string { return Support::now($this->config); }

    private function businessDate(?string $now = null): string
    {
        $now = $now ?? $this->now();
        $today = substr($now,0,10);
        return substr($now,11,8) < '08:00:00'
            ? (new \DateTimeImmutable($today,new \DateTimeZone('Asia/Shanghai')))->modify('-1 day')->format('Y-m-d')
            : $today;
    }

    public function install(): void
    {
        Schema::install($this->db);
        Schema::transaction($this->db, function (): void {
            foreach (['class_name'=>'示例班级','check_time'=>'22:30','due_time'=>'22:35','reminder_time'=>'22:25','paused_dates'=>[],'retention_days'=>180] as $key=>$default) {
                if (Schema::get($this->db, $key) === null) Schema::set($this->db, $key, $this->config[$key] ?? $default);
            }
            if (Schema::get($this->db, 'scheduler_cursor') === null) Schema::set($this->db, 'scheduler_cursor', substr($this->now(), 0, 10));
        });
        Grade::install($this->db);
    }

    public function handle(string $action, array $user, array $data): array
    {
        $action = str_replace('.', '_', $action);
        return Schema::transaction($this->db, function () use ($action, $user, $data): array {
            $raw = Support::one($this->db, 'SELECT id,student_no,name,role,status,totp_enabled FROM users WHERE id=?', [(int)($user['id'] ?? 0)]);
            if (!$raw || $raw['status'] !== 'active') throw new ApiError('账号已失效', 'UNAUTHENTICATED', 401);
            if (Auth::totpPolicy($this->db, $raw)['totp_required'] && !$raw['totp_enabled']) throw new ApiError('请先绑定该账号的验证器', 'MFA_SETUP_REQUIRED', 403);
            $u = array_replace($user, $raw); $u['id'] = (int)$u['id'];
            $this->scope=Grade::scope($this->db,$u); $this->scopeNames=null;
            if (!empty($data['class_id']) && !in_array($action,['class_create','class_rename','class_permissions_save','room_leader_change'],true)) {
                $class=Grade::requireClass($this->db,$u,$this->positive($data['class_id']));
                $this->scope=[(int)$class['id']];
            }
            return match ($action) {
                'classes' => ['classes'=>Grade::classes($this->db,$u)],
                'class_create' => $this->classCreate($u,$data),
                'class_rename' => $this->classRename($u,$data),
                'class_permissions_save' => $this->classPermissionsSave($u,$data),
                'room_leader_change' => $this->roomLeaderChange($u,$data),
                'tasks' => $this->listTasks($u, $data),
                'task_history' => $this->history($u, $data),
                'dashboard' => $this->dashboard($u, $data),
                'report_initial','report_update','report_correct' => $this->attendance($action, $u, $data),
                'reports' => $this->report($u, $data),
                'report_snapshot' => $this->snapshot($u, $data),
                'export' => $this->export($u, $data),
                'followups','inspections' => $this->activityList($action, $u, $data),
                'followup_save','inspection_save' => $this->activitySave($action, $u, $data),
                'roster' => $this->roster($u),
                'import_preview' => $this->importPreview($u, $data),
                'import_commit' => $this->importCommit($u, $data),
                'student_retire' => $this->retireStudent($u, $data),
                'exemption_save' => $this->exemptionSave($u, $data),
                'exemption_revoke' => $this->exemptionRevoke($u, $data),
                'settings' => $this->getSettings($u),
                'settings_save' => $this->saveSettings($u, $data),
                'prepare_today' => $this->prepareToday($data),
                'audit' => $this->audit($u, $data),
                'task_close','task_reopen' => $this->closeTask($action, $u, $data),
                'task_roster_adjust' => $this->adjustRoster($u, $data),
                'draft_get','draft_save' => $this->draft($action, $u, $data),
                default => throw new ApiError('接口不存在', 'NOT_FOUND', 404),
            };
        }, !in_array($action, self::READS, true));
    }

    private function classCreate(array $u, array $data): array
    {
        $this->manager($u,true); $this->recent($u);
        $name=Support::text($data['name'] ?? '',100);
        if ($name==='') throw new ApiError('请输入班级名称');
        if (Support::one($this->db,'SELECT name FROM class_names WHERE name=?',[$name])) throw new ApiError('班级名称已使用，或为历史班级名称','DUPLICATE_CLASS',409);
        Support::exec($this->db,'INSERT INTO classes (name,created_at) VALUES (?,?)',[$name,$this->now()]);
        $id=(int)$this->db->lastInsertId();
        Support::exec($this->db,'INSERT INTO class_names (name,class_id) VALUES (?,?)',[$name,$id]);
        Audit::write($this->db,$u['id'],'class.create',['class_id'=>$id,'name'=>$name]);
        return ['class'=>['id'=>$id,'name'=>$name]];
    }

    private function classRename(array $u, array $data): array
    {
        $this->manager($u,true); $this->recent($u);
        $class=Grade::requireClass($this->db,$u,$this->positive($data['class_id'] ?? 0));
        $name=Support::text($data['name'] ?? '',100);
        if ($name==='') throw new ApiError('请输入班级名称');
        if ($name===$class['name']) return ['class'=>$class];
        if (Support::one($this->db,'SELECT name FROM class_names WHERE name=?',[$name])) throw new ApiError('班级名称已使用或属于历史班级','DUPLICATE_CLASS',409);
        Support::exec($this->db,'INSERT INTO class_names (name,class_id) VALUES (?,?)',[$name,$class['id']]);
        Support::exec($this->db,'UPDATE classes SET name=? WHERE id=?',[$name,$class['id']]);
        $students=Support::all($this->db,'SELECT * FROM students WHERE class_name=? AND active=1',[$class['name']]);
        foreach ($students as $student) {
            Support::exec($this->db,'UPDATE students SET class_name=?,updated_at=? WHERE id=?',[$name,$this->now(),$student['id']]);
            Support::exec($this->db,'UPDATE placements SET valid_to=? WHERE student_id=? AND valid_to IS NULL',[$this->now(),$student['id']]);
            Support::exec($this->db,'INSERT INTO placements (student_id,room_id,student_no,name,class_name,leader_no,valid_from) VALUES (?,?,?,?,?,?,?)',[$student['id'],$student['room_id'],$student['student_no'],$student['name'],$name,$student['leader_no'],$this->now()]);
        }
        $this->prepare(substr($this->now(),0,10));
        Audit::write($this->db,$u['id'],'class.rename',['class_id'=>(int)$class['id'],'before'=>$class['name'],'after'=>$name]);
        return ['class'=>['id'=>(int)$class['id'],'name'=>$name]];
    }

    private function roomLeaderChange(array $u, array $data): array
    {
        $this->manager($u,true); $this->recent($u);
        $room=$this->positive($data['room_id'] ?? 0); $no=Support::text($data['student_no'] ?? '',64); $reason=Support::text($data['reason'] ?? '',500);
        if ($reason==='') throw new ApiError('请填写负责人交接原因');
        $leader=Support::one($this->db,'SELECT id FROM students WHERE student_no=? AND room_id=? AND active=1',[$no,$room]);
        if (!$leader) throw new ApiError('负责人必须是该寝的有效名册成员');
        $members=Support::all($this->db,'SELECT * FROM students WHERE room_id=? AND active=1',[$room]);
        if (!$members) throw new ApiError('寝室没有有效成员');
        foreach ($members as $student) {
            if ($student['leader_no']===$no) continue;
            Support::exec($this->db,'UPDATE students SET leader_no=?,updated_at=? WHERE id=?',[$no,$this->now(),$student['id']]);
            Support::exec($this->db,'UPDATE placements SET valid_to=? WHERE student_id=? AND valid_to IS NULL',[$this->now(),$student['id']]);
            Support::exec($this->db,'INSERT INTO placements (student_id,room_id,student_no,name,class_name,leader_no,valid_from) VALUES (?,?,?,?,?,?,?)',[$student['id'],$room,$student['student_no'],$student['name'],$student['class_name'],$no,$this->now()]);
        }
        $this->prepare(substr($this->now(),0,10));
        Audit::write($this->db,$u['id'],'room.leader_change',['room_id'=>$room,'student_no'=>$no,'reason'=>$reason]);
        return ['message'=>'负责人已更新；已冻结的当晚记录不变'];
    }

    private function classPermissionsSave(array $u, array $data): array
    {
        $this->manager($u,true); $this->recent($u);
        $id=$this->positive($data['user_id'] ?? 0);
        $target=Support::one($this->db,"SELECT id FROM users WHERE id=? AND role='committee' AND status<>'disabled'",[$id]);
        $classes=$data['class_ids'] ?? null;
        if (!$target || !is_array($classes) || !array_is_list($classes) || count($classes)>500) throw new ApiError('请选择有效的班委和班级');
        $classes=array_map(fn($id)=>$this->positive($id),$classes);
        if (count($classes)!==count(array_unique($classes))) throw new ApiError('不能重复选择班级');
        foreach ($classes as $class) Grade::requireClass($this->db,$u,$class);
        Support::exec($this->db,'DELETE FROM class_permissions WHERE user_id=?',[$id]);
        foreach ($classes as $class) Support::exec($this->db,'INSERT INTO class_permissions (user_id,class_id,created_at) VALUES (?,?,?)',[$id,$class,$this->now()]);
        Support::exec($this->db,'UPDATE users SET auth_version=auth_version+1,updated_at=? WHERE id=?',[$this->now(),$id]);
        Audit::write($this->db,$u['id'],'class.permissions',['user_id'=>$id,'class_ids'=>$classes]);
        return ['message'=>'班级授权已更新'];
    }

    private function inScope(string $class): bool
    {
        if ($this->scope===null) return true;
        if ($this->scopeNames===null) {
            $this->scopeNames=[];
            foreach (Support::all($this->db,'SELECT name,class_id FROM class_names') as $row) if (in_array((int)$row['class_id'],$this->scope,true)) $this->scopeNames[$row['name']]=true;
        }
        return isset($this->scopeNames[$class]);
    }

    private function scopedMember(array $member): void
    {
        if (!$this->inScope((string)$member['class_name'])) throw new ApiError('成员不在当前授权范围内','FORBIDDEN',403);
    }

    private function fullRoom(array $task): void
    {
        foreach (Support::all($this->db,'SELECT class_name FROM night_members WHERE task_id=? AND room_id=? AND included=1',[$task['task_id'],$task['room_id']]) as $m) $this->scopedMember($m);
    }

    private function manager(array $u, bool $admin = false): void
    {
        if (!in_array($u['role'], $admin ? ['admin'] : ['admin','committee'], true)) throw new ApiError('没有此操作的权限', 'FORBIDDEN', 403);
    }

    private function recent(array $u): void
    {
        if ((int)($u['reauth_until'] ?? 0) < time()) throw new ApiError('请重新验证身份', 'REAUTH_REQUIRED', 403);
    }

    private function date(mixed $value): string
    {
        $date = Support::text($value, 10);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Shanghai'));
        if (!$d || $d->format('Y-m-d') !== $date) throw new ApiError('日期请使用有效的 YYYY-MM-DD 格式');
        return $date;
    }

    private function datetime(mixed $value, bool $end = false): string
    {
        $s = Support::text($value, 19);
        if (strlen($s) === 10) return $this->date($s) . ($end ? ' 23:59:59' : ' 00:00:00');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $s, new \DateTimeZone('Asia/Shanghai'));
        if (!$d || $d->format('Y-m-d H:i:s') !== $s) throw new ApiError('日期时间格式应为 YYYY-MM-DD HH:mm:ss');
        return $s;
    }

    private function positive(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) throw new ApiError('记录编号格式不正确');
        if (!preg_match('/^[1-9][0-9]{0,14}$/D', (string)$value)) throw new ApiError('记录编号格式不正确');
        return (int)$value;
    }

    private function taskKey(mixed $value): array
    {
        if (!is_string($value) || !preg_match('/^([1-9][0-9]{0,14})\.([1-9][0-9]{0,14})$/D', $value, $m)) throw new ApiError('请使用完整的寝室任务编号');
        return [(int)$m[1], (int)$m[2]];
    }

    private function task(array $u, mixed $key, bool $freeze = false): array
    {
        [$night, $room] = $this->taskKey($key);
        $t = Support::one($this->db, 'SELECT r.*,n.business_date,n.check_at,n.due_at,n.frozen_at,n.`delayed` FROM room_tasks r JOIN night_tasks n ON n.id=r.task_id WHERE r.task_id=? AND r.room_id=?', [$night,$room]);
        if (!$t || !$this->authorized($u, $t)) throw new ApiError('任务不存在或不在当前授权范围内', 'FORBIDDEN', 403);
        if ($freeze && !$t['frozen_at'] && $this->now() >= $t['check_at'] && $t['phase'] !== 'paused') {
            $this->freeze($night);
            return $this->task($u, $key);
        }
        return $t;
    }

    private function authorized(array $u, array $t): bool
    {
        if ($this->scope===null) return $u['role']==='admin';
        foreach (Support::all($this->db,'SELECT class_name FROM night_members WHERE task_id=? AND room_id=? AND included=1',[$t['task_id'],$t['room_id']]) as $m) if ($this->inScope($m['class_name'])) return true;
        return false;
    }

    private function roomPayload(array $t): array
    {
        $t['id'] = $t['task_id'] . '.' . $t['room_id'];
        foreach (['task_id','room_id','roster_version','report_version'] as $key) $t[$key] = (int)$t[$key];
        $t['room'] = $t['building'] . ' · ' . $t['room_number']; $t['late'] = (bool)$t['late'];
        if ($t['phase'] === 'draft' && $this->now() >= $t['check_at']) $t['phase'] = 'open';
        $members = Support::all($this->db, 'SELECT * FROM night_members WHERE task_id=? AND room_id=? AND included=1 ORDER BY student_no', [$t['task_id'],$t['room_id']]);
        if ($this->scope!==null) $members=array_values(array_filter($members,fn($m)=>$this->inScope($m['class_name'])));
        foreach ($members as &$m) {
            foreach (['student_id','version'] as $key) $m[$key] = (int)$m[$key];
            $m['status'] = $m['current_status'];
            $m['exemption'] = $this->validExemption($m['student_id'], $t['business_date']);
            $m['frozen_exemption'] = $m['exemption_json'] ? json_decode($m['exemption_json'], true, 512, JSON_THROW_ON_ERROR) : null;
            $m['task_id'] = $t['id']; $m['room'] = $t['room'];
        }
        unset($m); $t['members'] = $members; $t['roster_empty'] = count($members) === 0;
        $t['responsible'] = json_decode($t['responsible_json'], true, 512, JSON_THROW_ON_ERROR);
        if ($this->scope!==null) {
            $complete=count($members)>0 && !array_filter($members,fn($m)=>$m['initial_status']==='unreported' && $m['corrected_initial_status']===null);
            $times=[];
            foreach ($members as $member) {
                $event=Support::one($this->db,"SELECT recorded_at FROM attendance_events WHERE task_id=? AND student_id=? AND type='initial' ORDER BY id LIMIT 1",[$t['task_id'],$member['student_id']]);
                if ($event) $times[]=$event['recorded_at'];
            }
            sort($times); $t['submitted_at']=$complete && $times ? end($times) : ($complete ? ($t['submitted_at'] ?: $this->now()) : null);
            $t['late']=(bool)array_filter($times,fn($time)=>$time>$t['due_at']); $t['responsible']=[]; $t['leader_no']=''; $t['leader_name']='';
        }
        unset($t['responsible_json']);
        return $t;
    }

    private function listTasks(array $u, array $data): array
    {
        $date = $this->date($data['date'] ?? $this->businessDate()); $tasks = [];
        foreach (Support::all($this->db, 'SELECT r.*,n.business_date,n.check_at,n.due_at,n.frozen_at,n.`delayed` FROM room_tasks r JOIN night_tasks n ON n.id=r.task_id WHERE n.business_date=? ORDER BY r.building,r.room_number', [$date]) as $t) {
            if ($this->authorized($u,$t)) $tasks[] = $this->roomPayload($t);
        }
        return ['tasks'=>$tasks,'open_tasks'=>[],'server_time'=>$this->now(),'business_date'=>$date,'check_time'=>Schema::get($this->db,'check_time','22:30'),'reminder_time'=>Schema::get($this->db,'reminder_time','22:25')];
    }

    private function history(array $u, array $data): array
    {
        $t=$this->task($u,$data['task_id'] ?? '');
        $events=Support::all($this->db,'SELECT e.*,m.name AS student_name,m.student_no,r.building,r.room_number FROM attendance_events e LEFT JOIN night_members m ON m.task_id=e.task_id AND m.student_id=e.student_id JOIN room_tasks r ON r.task_id=e.task_id AND r.room_id=e.room_id WHERE e.task_id=? AND (e.room_id=? OR e.student_id IN (SELECT student_id FROM night_members WHERE task_id=? AND room_id=? AND included=1)) ORDER BY e.id',[$t['task_id'],$t['room_id'],$t['task_id'],$t['room_id']]);
        if ($this->scope!==null) {
            $visible=array_column($this->roomPayload($t)['members'],'student_id');
            $events=array_values(array_filter($events,fn($e)=>$e['student_id']!==null && in_array((int)$e['student_id'],$visible,true)));
        }
        foreach ($events as &$e) {
            $e['before']=json_decode($e['before_json'],true,512,JSON_THROW_ON_ERROR); $e['after']=json_decode($e['after_json'],true,512,JSON_THROW_ON_ERROR);
            $e['room']=$e['building'].' · '.$e['room_number']; unset($e['before_json'],$e['after_json']);
        }
        unset($e); return ['task'=>$this->roomPayload($t),'events'=>$events];
    }

    private function dashboard(array $u, array $data): array
    {
        $this->manager($u); $date = $this->date($data['date'] ?? $this->businessDate());
        $report = Reports::scoped($this->db,Reports::build($this->db,$date,$this->now()),$this->scope);
        return $report + ['tasks'=>$this->listTasks($u,['date'=>$date])['tasks'],'recent_events'=>array_slice(array_reverse($report['events']),0,30),'server_time'=>$this->now(),'job_runs'=>Support::all($this->db,'SELECT * FROM job_runs ORDER BY id DESC LIMIT 10')];
    }

    private function validExemption(int $student, string $date): ?array
    {
        return Support::one($this->db, 'SELECT id,start_date,end_date,source,note,created_at FROM exemptions WHERE student_id=? AND start_date<=? AND end_date>=? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1', [$student,$date,$date]);
    }

    private function settingsValues(): array
    {
        $out = [];
        foreach (['grade_name','check_time','due_time','reminder_time','paused_dates','retention_days','totp_student_no'] as $key) $out[$key] = Schema::get($this->db,$key);
        return $out + self::publicSettings($this->db);
    }

    public static function publicSettings(PDO $db): array
    {
        return ['filing_icp'=>(string)Schema::get($db,'filing_icp',''), 'filing_police'=>(string)Schema::get($db,'filing_police','')];
    }

    private function prepareToday(array $data): array
    {
        if ($data !== []) throw new ApiError('不能指定查寝日期或其他参数');
        $now=$this->now(); $date=$this->businessDate($now);
        // Never invent an absent previous-night task when browsing after midnight.
        if ($date===substr($now,0,10) || Support::one($this->db,'SELECT id FROM night_tasks WHERE business_date=?',[$date])) $this->prepare($date);
        return ['business_date'=>$date,'server_time'=>$this->now()];
    }

    public function prepare(string $date): void
    {
        $this->date($date);
        Schema::transaction($this->db, function () use ($date): void {
            $n = Support::one($this->db,'SELECT * FROM night_tasks WHERE business_date=?',[$date]);
            if (!$n) {
                $s = $this->settingsValues(); $phase = in_array($date,$s['paused_dates'],true) ? 'paused' : 'draft';
                Support::exec($this->db,'INSERT INTO night_tasks (business_date,check_at,due_at,phase,created_at,updated_at) VALUES (?,?,?,?,?,?)',[$date,$date.' '.$s['check_time'].':00',$date.' '.$s['due_time'].':00',$phase,$this->now(),$this->now()]);
                $n = Support::one($this->db,'SELECT * FROM night_tasks WHERE business_date=?',[$date]);
            }
            if (!$n['frozen_at']) {
                $at = $this->now() < $n['check_at'] ? $this->now() : ($n['created_at'] > $n['check_at'] ? $this->now() : $n['check_at']);
                $this->refreshRoster($n,$at);
                if ($this->now() >= $n['check_at'] && $n['phase'] !== 'paused') $this->freeze((int)$n['id']);
            }
        });
    }

    private function refreshRoster(array $n, string $at): void
    {
        $placements = Support::all($this->db,'SELECT * FROM placements WHERE valid_from<=? AND (valid_to IS NULL OR valid_to>?) ORDER BY student_id',[$at,$at]);
        $desired = array_map(fn($p)=>[(int)$p['student_id'],(int)$p['room_id'],$p['student_no'],$p['name'],$p['class_name'],$p['leader_no']],$placements);
        $current = Support::all($this->db,'SELECT m.*,r.leader_no FROM night_members m JOIN room_tasks r ON r.task_id=m.task_id AND r.room_id=m.room_id WHERE m.task_id=? AND m.included=1 ORDER BY m.student_id',[$n['id']]);
        $existing = array_map(fn($p)=>[(int)$p['student_id'],(int)$p['room_id'],$p['student_no'],$p['name'],$p['class_name'],$p['leader_no']],$current);
        if ($desired === $existing) return;
        $version = (int)$n['roster_version'] + 1;
        Support::exec($this->db,'DELETE FROM night_members WHERE task_id=?',[$n['id']]);
        Support::exec($this->db,'DELETE FROM room_tasks WHERE task_id=?',[$n['id']]);
        $rooms = [];
        foreach ($placements as $p) {
            $room = (int)$p['room_id'];
            if (!isset($rooms[$room])) {
                $r = Support::one($this->db,'SELECT * FROM rooms WHERE id=?',[$room]);
                $leader = Support::one($this->db,'SELECT name FROM placements WHERE student_no=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>?) ORDER BY id DESC LIMIT 1',[$p['leader_no'],$at,$at]);
                $responsible = Support::all($this->db,'SELECT g.user_id,u.student_no,u.name FROM grants g JOIN users u ON u.id=g.user_id WHERE g.room_id=? AND g.valid_from<=? AND (g.valid_to IS NULL OR g.valid_to>=?) AND (g.revoked_at IS NULL OR g.revoked_at>?) AND g.created_at<=? AND (g.task_id IS NULL OR g.task_id=?)',[$room,$at,$at,$at,$at,$n['id']]);
                Support::exec($this->db,'INSERT INTO room_tasks (task_id,room_id,building,room_number,leader_no,leader_name,responsible_json,phase,roster_version,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$n['id'],$room,$r['building'],$r['room_number'],$p['leader_no'],$leader['name'] ?? '待核实',Support::json($responsible),$n['phase'],$version,$this->now()]);
                $rooms[$room] = true;
            }
            $exemption = $this->validExemption((int)$p['student_id'],$n['business_date']);
            Support::exec($this->db,'INSERT INTO night_members (task_id,room_id,student_id,student_no,name,class_name,exemption_json,initial_location,location,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$n['id'],$room,$p['student_id'],$p['student_no'],$p['name'],$p['class_name'],$exemption ? Support::json($exemption) : null,'','',$this->now()]);
        }
        Support::exec($this->db,'UPDATE night_tasks SET roster_version=?,updated_at=? WHERE id=?',[$version,$this->now(),$n['id']]);
    }

    private function freeze(int $id): void
    {
        $n = Support::one($this->db,'SELECT * FROM night_tasks WHERE id=?',[$id]);
        if (!$n || $n['frozen_at'] || $n['phase'] === 'paused' || $this->now() < $n['check_at']) return;
        $at=$n['created_at'] > $n['check_at'] ? $this->now() : $n['check_at'];
        $this->refreshRoster($n,$at);
        foreach (Support::all($this->db,'SELECT student_id FROM night_members WHERE task_id=?',[$id]) as $member) {
            $e=Support::one($this->db,'SELECT id,start_date,end_date,source,note,created_at FROM exemptions WHERE student_id=? AND start_date<=? AND end_date>=? AND created_at<=? AND (revoked_at IS NULL OR revoked_at>?) ORDER BY id DESC LIMIT 1',[$member['student_id'],$n['business_date'],$n['business_date'],$at,$at]);
            Support::exec($this->db,'UPDATE night_members SET exemption_json=? WHERE task_id=? AND student_id=?',[$e?Support::json($e):null,$id,$member['student_id']]);
        }
        foreach (Support::all($this->db,'SELECT room_id FROM room_tasks WHERE task_id=?',[$id]) as $room) {
            $responsible=Support::all($this->db,'SELECT g.user_id,u.student_no,u.name FROM grants g JOIN users u ON u.id=g.user_id WHERE g.room_id=? AND g.valid_from<=? AND (g.valid_to IS NULL OR g.valid_to>=?) AND (g.revoked_at IS NULL OR g.revoked_at>?) AND g.created_at<=? AND (g.task_id IS NULL OR g.task_id=?)',[$room['room_id'],$at,$at,$at,$at,$id]);
            Support::exec($this->db,'UPDATE room_tasks SET responsible_json=? WHERE task_id=? AND room_id=?',[Support::json($responsible),$id,$room['room_id']]);
        }
        Support::exec($this->db,"UPDATE night_tasks SET phase='open',frozen_at=?,`delayed`=?,updated_at=? WHERE id=?",[$this->now(),$this->now() > $n['check_at'] ? 1 : 0,$this->now(),$id]);
        Support::exec($this->db,"UPDATE room_tasks SET phase='open',updated_at=? WHERE task_id=?",[$this->now(),$id]);
        Audit::write($this->db,null,'task.freeze',['task_id'=>$id,'business_date'=>$n['business_date'],'actual_time'=>$this->now()]);
    }

    private function event(array $u, array $t, ?int $student, string $type, array $before, array $after, string $reason = '', ?string $occurred = null): void
    {
        Support::exec($this->db,'INSERT INTO attendance_events (task_id,room_id,student_id,type,before_json,after_json,before_status,after_status,actor_id,actor_name,reason,occurred_at,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$t['task_id'],$t['room_id'],$student,$type,Support::json($before),Support::json($after),$before['current_status'] ?? $before['status'] ?? null,$after['current_status'] ?? $after['status'] ?? null,$u['id'] ?? null,$u['name'] ?? '系统',$reason,$occurred,$this->now()]);
    }

    private function status(mixed $status, int $student, string $date, string $location): string
    {
        if (!is_string($status) || !in_array($status,self::STATUSES,true)) throw new ApiError('请选择有效状态，不能提交未登记');
        if (in_array($status,['away','unknown'],true) && $location === '') throw new ApiError('未归或待核实人员请填写已知情况');
        if ($status === 'exempt' && !$this->validExemption($student,$date)) throw new ApiError('该同学尚无当晚已核实的返寝例外');
        return $status;
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        return array_map(fn($v)=>$this->canonical($v),$value);
    }

    private function attendance(string $action, array $u, array $data): array
    {
        $t = $this->task($u,$data['task_id'] ?? '',true);
        $key = Support::text($data['idempotency_key'] ?? '',100);
        if (!preg_match('/^[A-Za-z0-9._:\-]{12,100}$/D',$key)) throw new ApiError('提交缺少有效的去重标识');
        $hash = hash('sha256',Support::json($this->canonical($data)));
        $scopeTag=hash('sha256',Support::json($this->scope));
        $saved = Support::one($this->db,'SELECT * FROM request_keys WHERE actor_id=? AND action=? AND token=?',[$u['id'],$action,$key]);
        if ($saved) {
            if (!hash_equals($saved['request_hash'],$hash)) throw new ApiError('同一提交标识不能用于不同内容，请重新打开表单', 'IDEMPOTENCY_CONFLICT',409);
            $cached=json_decode($saved['result_json'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($cached) || !is_string($cached['scope_tag'] ?? null) || !hash_equals($cached['scope_tag'],$scopeTag) || !is_array($cached['result'] ?? null)) throw new ApiError('授权范围已变化，请重新读取当前结果', 'SCOPE_CHANGED',409);
            return $cached['result'];
        }
        if ($this->now() < $t['check_at'] || !$t['frozen_at']) throw new ApiError('正式查寝尚未开放', 'TASK_NOT_OPEN',409);
        if (in_array($t['phase'],['closed','paused'],true)) throw new ApiError('任务已结项或暂停，请联系班委', 'TASK_CLOSED',409);
        if (!isset($data['version']) || (string)$data['version'] !== (string)$t['report_version']) throw new ApiError('数据已变化，请重新读取后提交', 'VERSION_CONFLICT',409);
        if ($action === 'report_initial') $this->initial($u,$t,$data);
        elseif ($action === 'report_update') $this->update($u,$t,$data);
        else $this->correct($u,$t,$data);
        Support::exec($this->db,'UPDATE room_tasks SET report_version=report_version+1,updated_at=? WHERE task_id=? AND room_id=?',[$this->now(),$t['task_id'],$t['room_id']]);
        $result = ['task'=>$this->roomPayload($this->task($u,$data['task_id']))];
        Support::exec($this->db,'INSERT INTO request_keys (actor_id,action,token,request_hash,result_json,created_at) VALUES (?,?,?,?,?,?)',[$u['id'],$action,$key,$hash,Support::json(['scope_tag'=>$scopeTag,'result'=>$result]),$this->now()]);
        Audit::write($this->db,$u['id'],$action,['task_id'=>$data['task_id'],'version'=>$t['report_version']+1]);
        return $result;
    }

    private function quickLimit(string $client, string $kind): void
    {
        $ip=substr($client,0,128);
        $bucket=hash_hmac('sha256','quick:'.$kind.':'.$ip,base64_decode($this->config['app_key'],true) ?: '');
        $allowed=Schema::transaction($this->db,function () use ($bucket): bool {
            $now=time(); $row=Support::one($this->db,'SELECT window_start,attempts FROM rate_limits WHERE bucket=?',[$bucket]);
            if (!$row) { Support::exec($this->db,'INSERT INTO rate_limits (bucket,window_start,attempts,blocked_until) VALUES (?,?,1,0)',[$bucket,$now]); return true; }
            if ($now-(int)$row['window_start']>=300) { Support::exec($this->db,'UPDATE rate_limits SET window_start=?,attempts=1,blocked_until=0 WHERE bucket=?',[$now,$bucket]); return true; }
            $count=(int)$row['attempts']+1; Support::exec($this->db,'UPDATE rate_limits SET attempts=? WHERE bucket=?',[$count,$bucket]);
            return $count<=60;
        });
        if (!$allowed) throw new ApiError('操作过于频繁，请五分钟后重试','RATE_LIMITED',429);
    }

    private function quickTask(array $data): array
    {
        $no=Support::text($data['student_no'] ?? '',64); $room=Support::text($data['room_number'] ?? '',100);
        if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/D',$no) || $room==='') throw new ApiError('请填写寝室长学号和寝室号');
        $now=$this->now(); $today=substr($now,0,10); $date=$this->businessDate($now);
        if ($date!==$today && !Support::one($this->db,'SELECT id FROM night_tasks WHERE business_date=?',[$date])) throw new ApiError('未找到唯一的当晚寝室任务，请核对信息或联系班委','QUICK_NOT_FOUND',404);
        $this->prepare($date);
        $matches=Support::all($this->db,'SELECT r.*,n.business_date,n.check_at,n.due_at,n.frozen_at,s.student_no AS claimed_no FROM room_tasks r JOIN night_tasks n ON n.id=r.task_id JOIN students s ON s.room_id=r.room_id AND s.active=1 WHERE n.business_date=? AND r.room_number=? AND s.student_no=? AND s.leader_no=s.student_no',[$date,$room,$no]);
        $matches=array_values(array_filter($matches,fn($t)=>$t['room_number']===$room && $t['claimed_no']===$no));
        if (count($matches)!==1) throw new ApiError('未找到唯一的当晚寝室任务，请核对信息或联系班委','QUICK_NOT_FOUND',404);
        return $matches[0];
    }

    private function quickPayload(array $t): array
    {
        $members=Support::all($this->db,'SELECT student_id,name,class_name,initial_status,initial_location,corrected_initial_status,current_status FROM night_members WHERE task_id=? AND room_id=? AND included=1 ORDER BY student_no',[$t['task_id'],$t['room_id']]);
        foreach ($members as &$m) { $m['student_id']=(int)$m['student_id']; $m['immutable']=$m['initial_status']!=='unreported' || $m['corrected_initial_status']!==null; }
        unset($m);
        return ['business_date'=>$t['business_date'],'room'=>$t['building'].' · '.$t['room_number'],'check_at'=>$t['check_at'],'due_at'=>$t['due_at'],'phase'=>$t['phase'],'submitted_at'=>$t['submitted_at'],'roster_version'=>(int)$t['roster_version'],'version'=>(int)$t['report_version'],'members'=>$members];
    }

    public function quick(string $action, array $data, string $client): array
    {
        $this->scope=null;
        if (!in_array($action,['quick_lookup','quick_initial','quick_update'],true)) throw new ApiError('接口不存在','NOT_FOUND',404);
        $this->quickLimit($client,$action==='quick_lookup'?'lookup':'submit');
        return Schema::transaction($this->db,function () use ($action,$data): array {
            $t=$this->quickTask($data);
            if ($action==='quick_lookup') return ['task'=>$this->quickPayload($t),'server_time'=>$this->now()];
            $allowed=$action==='quick_initial' ? ['student_no','room_number','version','roster_version','members','idempotency_key'] : ['student_no','room_number','version','student_ids','status','location','idempotency_key'];
            foreach ($data as $key=>$value) if (!in_array($key,$allowed,true)) throw new ApiError('请求包含不支持的字段');
            $key=Support::text($data['idempotency_key'] ?? '',100);
            if (!preg_match('/^[A-Za-z0-9._:\-]{12,100}$/D',$key)) throw new ApiError('提交缺少有效的去重标识');
            $cacheAction=$action.':'.$t['task_id'].':'.$t['room_id']; $hash=hash('sha256',Support::json($this->canonical($data)));
            $saved=Support::one($this->db,'SELECT request_hash,result_json FROM request_keys WHERE actor_id=0 AND action=? AND token=?',[$cacheAction,$key]);
            if ($saved) { if (!hash_equals($saved['request_hash'],$hash)) throw new ApiError('同一提交标识不能用于不同内容','IDEMPOTENCY_CONFLICT',409); return json_decode($saved['result_json'],true,512,JSON_THROW_ON_ERROR); }
            if ($this->now()<$t['check_at'] || !$t['frozen_at']) throw new ApiError('正式查寝尚未开放','TASK_NOT_OPEN',409);
            if (in_array($t['phase'],['closed','paused'],true)) throw new ApiError('任务已结项或暂停，请联系班委','TASK_CLOSED',409);
            if (!isset($data['version']) || (string)$data['version']!==(string)$t['report_version']) throw new ApiError('数据已变化，请重新读取后提交','VERSION_CONFLICT',409);
            $actor=['id'=>null,'name'=>'便捷入口（未验证身份）'];
            if ($action==='quick_initial') $this->initial($actor,$t,$data); else $this->update($actor,$t,$data);
            Support::exec($this->db,'UPDATE room_tasks SET report_version=report_version+1,updated_at=? WHERE task_id=? AND room_id=?',[$this->now(),$t['task_id'],$t['room_id']]);
            $updated=Support::one($this->db,'SELECT r.*,n.business_date,n.check_at,n.due_at,n.frozen_at FROM room_tasks r JOIN night_tasks n ON n.id=r.task_id WHERE r.task_id=? AND r.room_id=?',[$t['task_id'],$t['room_id']]);
            $result=['task'=>$this->quickPayload($updated)];
            Support::exec($this->db,'INSERT INTO request_keys (actor_id,action,token,request_hash,result_json,created_at) VALUES (0,?,?,?,?,?)',[$cacheAction,$key,$hash,Support::json($result),$this->now()]);
            Audit::write($this->db,null,$action,['task_id'=>$t['task_id'].'.'.$t['room_id'],'claimed_student_no'=>$data['student_no'],'identity_verified'=>false,'version'=>(int)$t['report_version']+1]);
            return $result;
        });
    }

    private function initial(array $u, array $t, array $data): void
    {
        if ($t['submitted_at']) throw new ApiError('已经完成初报，请使用补报或更正', 'ALREADY_SUBMITTED',409);
        if ((string)($data['roster_version'] ?? '') !== (string)$t['roster_version']) throw new ApiError('当晚名册已变化，请重新核对', 'ROSTER_CONFLICT',409);
        $members = Support::all($this->db,'SELECT * FROM night_members WHERE task_id=? AND room_id=? AND included=1 ORDER BY student_id',[$t['task_id'],$t['room_id']]);
        if ($this->scope!==null) $members=array_values(array_filter($members,fn($m)=>$this->inScope($m['class_name'])));
        $rows = $data['members'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== count($members) || !$members) throw new ApiError('初报必须包含本寝完整名单');
        $map = [];
        foreach ($rows as $r) {
            if (!is_array($r)) throw new ApiError('成员格式不正确');
            $id = $this->positive($r['student_id'] ?? 0);
            if (isset($map[$id])) throw new ApiError('成员不能重复');
            $map[$id] = $r;
        }
        foreach ($members as $m) {
            $id = (int)$m['student_id']; if (!isset($map[$id])) throw new ApiError('名单中存在漏人或越权成员', 'FORBIDDEN',403);
            $r = $map[$id]; $location = Support::text($r['location'] ?? '',200);
            if ($m['initial_status'] !== 'unreported' || $m['corrected_initial_status'] !== null) {
                if (($r['status'] ?? '') !== $m['initial_status'] || $location !== $m['initial_location'] || (isset($r['current_status']) && $r['current_status'] !== $m['current_status'])) throw new ApiError('转入成员已有原始申报，不能通过新寝初报覆盖；请使用补报或更正', 'INITIAL_IMMUTABLE',409);
                continue;
            }
            $initial = $this->status($r['status'] ?? '',$id,$t['business_date'],$location);
            $currentValue=$r['current_status'] ?? $initial;
            $currentLocation=Support::text($r['current_location'] ?? ($currentValue===$initial?$location:''),200);
            $current = $this->status($currentValue,$id,$t['business_date'],$currentLocation);
            $expected = empty($r['expected_return_at']) ? null : $this->datetime($r['expected_return_at']);
            $occurred = empty($r['occurred_at']) ? null : $this->datetime($r['occurred_at']);
            if ($occurred !== null && ($occurred < $t['check_at'] || $occurred > $this->now())) throw new ApiError('变化时间必须在查寝时点与接收时点之间');
            $return = $current === 'present' && $initial !== 'present' ? ($occurred ?? $this->now()) : null;
            $after = ['initial_status'=>$initial,'initial_location'=>$location,'current_status'=>$current,'location'=>$currentLocation,'return_at'=>$return];
            Support::exec($this->db,'UPDATE night_members SET initial_status=?,initial_location=?,current_status=?,location=?,expected_return_at=?,return_at=?,updated_at=?,version=version+1 WHERE task_id=? AND student_id=?',[$initial,$location,$current,$currentLocation,$expected,$return,$this->now(),$t['task_id'],$id]);
            $this->event($u,$t,$id,'initial',['current_status'=>'unreported'],$after,'',$occurred);
        }
        $remaining=Support::one($this->db,"SELECT COUNT(*) AS n FROM night_members WHERE task_id=? AND room_id=? AND included=1 AND initial_status='unreported' AND corrected_initial_status IS NULL",[$t['task_id'],$t['room_id']]);
        if ((int)$remaining['n']===0) Support::exec($this->db,'UPDATE room_tasks SET submitted_at=?,late=? WHERE task_id=? AND room_id=?',[$this->now(),$this->now() > $t['due_at'] ? 1 : 0,$t['task_id'],$t['room_id']]);
        Support::exec($this->db,'DELETE FROM drafts WHERE task_id=? AND room_id=?',[$t['task_id'],$t['room_id']]);
    }

    private function member(array $t, mixed $id): array
    {
        $id = $this->positive($id);
        $m = Support::one($this->db,'SELECT * FROM night_members WHERE task_id=? AND room_id=? AND student_id=? AND included=1',[$t['task_id'],$t['room_id'],$id]);
        if (!$m) throw new ApiError('成员不在当前任务授权范围内', 'FORBIDDEN',403);
        $this->scopedMember($m);
        return $m;
    }

    private function update(array $u, array $t, array $data): void
    {
        $ids = $data['student_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 1000) throw new ApiError('请选择需要更新的人员');
        $ids = array_map(fn($id)=>$this->positive($id),$ids);
        if (count(array_unique($ids)) !== count($ids)) throw new ApiError('成员不能重复');
        $members = array_map(fn($id)=>$this->member($t,$id),$ids);
        $location = Support::text($data['location'] ?? '',200); $reason = Support::text($data['reason'] ?? '',500);
        $occurred = empty($data['occurred_at']) ? null : $this->datetime($data['occurred_at']);
        if ($occurred !== null && ($occurred < $t['check_at'] || $occurred > $this->now())) throw new ApiError('返寝时间必须在查寝时点与接收时点之间');
        foreach ($members as $m) {
            if ($m['initial_status']==='unreported' && $m['corrected_initial_status']===null) throw new ApiError('请先登记该成员的初报','INITIAL_REQUIRED',409);
            $status = $this->status($data['status'] ?? '',(int)$m['student_id'],$t['business_date'],$location);
            $return = $status === 'present' ? ($occurred ?? $this->now()) : null;
            $after = ['current_status'=>$status,'location'=>$location,'return_at'=>$return];
            Support::exec($this->db,'UPDATE night_members SET current_status=?,location=?,return_at=?,updated_at=?,version=version+1 WHERE task_id=? AND student_id=?',[$status,$location,$return,$this->now(),$t['task_id'],$m['student_id']]);
            $this->event($u,$t,(int)$m['student_id'],$status === 'present' ? 'return' : 'update',$m,$after,$reason,$occurred);
        }
    }

    private function correct(array $u, array $t, array $data): void
    {
        $m = $this->member($t,$data['student_id'] ?? 0); $target = $data['target'] ?? 'current';
        if (!in_array($target,['initial','current'],true)) throw new ApiError('更正范围无效');
        if ($target === 'initial') $this->manager($u);
        if ($m['initial_status']==='unreported' && $m['corrected_initial_status']===null) {
            $reported=array_filter($this->roomPayload($t)['members'],fn($member)=>$member['initial_status']!=='unreported' || $member['corrected_initial_status']!==null);
            if ($target!=='initial' || !$reported) throw new ApiError('请先登记该成员的初报','INITIAL_REQUIRED',409);
        }
        $reason = Support::text($data['reason'] ?? '',500); if ($reason === '') throw new ApiError('更正必须说明原因');
        $location = Support::text($data['location'] ?? '',200); $status = $this->status($data['status'] ?? '',(int)$m['student_id'],$t['business_date'],$location);
        if ($target === 'initial') {
            Support::exec($this->db,'UPDATE night_members SET corrected_initial_status=?,corrected_initial_location=?,updated_at=?,version=version+1 WHERE task_id=? AND student_id=?',[$status,$location,$this->now(),$t['task_id'],$m['student_id']]);
            $after = ['corrected_initial_status'=>$status,'corrected_initial_location'=>$location,'target'=>'initial'];
            if ($m['current_status'] === 'unreported') {
                Support::exec($this->db,'UPDATE night_members SET current_status=?,location=? WHERE task_id=? AND student_id=?',[$status,$location,$t['task_id'],$m['student_id']]);
                $after['current_status']=$status; $after['location']=$location;
            }
        } else {
            $after = ['current_status'=>$status,'location'=>$location,'return_at'=>null,'target'=>'current'];
            Support::exec($this->db,'UPDATE night_members SET current_status=?,location=?,return_at=NULL,updated_at=?,version=version+1 WHERE task_id=? AND student_id=?',[$status,$location,$this->now(),$t['task_id'],$m['student_id']]);
        }
        $this->event($u,$t,(int)$m['student_id'],'correct',$m,$after,$reason);
        if ($target==='initial' && !$t['submitted_at']) {
            $remaining=Support::one($this->db,"SELECT COUNT(*) AS n FROM night_members WHERE task_id=? AND room_id=? AND included=1 AND initial_status='unreported' AND corrected_initial_status IS NULL",[$t['task_id'],$t['room_id']]);
            if ((int)$remaining['n']===0) Support::exec($this->db,'UPDATE room_tasks SET submitted_at=?,late=? WHERE task_id=? AND room_id=?',[$this->now(),$this->now()>$t['due_at']?1:0,$t['task_id'],$t['room_id']]);
        }
    }

    private function roster(array $u): array
    {
        $this->manager($u);
        $students=Support::all($this->db,'SELECT s.*,r.building,r.room_number,r.room_number AS room FROM students s JOIN rooms r ON r.id=s.room_id WHERE s.active=1 ORDER BY s.student_no');
        $students=array_values(array_filter($students,fn($s)=>$this->inScope($s['class_name'])));
        if ($this->scope!==null) foreach ($students as &$student) {
            $leader=Support::one($this->db,'SELECT class_name FROM students WHERE student_no=?',[$student['leader_no']]);
            if (!$leader || !$this->inScope($leader['class_name'])) $student['leader_no']='';
        }
        unset($student);
        $studentIds=array_map(fn($s)=>(int)$s['id'],$students);
        $roomIds=array_unique(array_map(fn($s)=>(int)$s['room_id'],$students));
        $rooms=array_values(array_filter(Support::all($this->db,'SELECT * FROM rooms ORDER BY building,room_number'),fn($r)=>in_array((int)$r['id'],$roomIds,true)));
        $exemptions=Support::all($this->db,'SELECT e.*,s.name AS student_name,s.student_no FROM exemptions e JOIN students s ON s.id=e.student_id WHERE e.revoked_at IS NULL ORDER BY e.id');
        $exemptions=array_values(array_filter($exemptions,fn($e)=>in_array((int)$e['student_id'],$studentIds,true)));
        $users=$u['role']==='admin'?Support::all($this->db,"SELECT id,student_no,name,role,status,totp_enabled FROM users WHERE role IN ('admin','committee') ORDER BY id"):[];
        $permissions=$u['role']==='admin'?Support::all($this->db,'SELECT user_id,class_id FROM class_permissions WHERE revoked_at IS NULL ORDER BY user_id,class_id'):[];
        return ['students'=>$students,'rooms'=>$rooms,'users'=>$users,'grants'=>[],'exemptions'=>$exemptions,'classes'=>Grade::classes($this->db,$u),'class_permissions'=>$permissions];
    }

    private function parseCsv(mixed $value, array $class, array $u): array
    {
        if (!is_string($value) || strlen($value) > 2*1024*1024 || str_contains($value,"\0") || !mb_check_encoding($value,'UTF-8')) throw new ApiError('请使用不超过 2 MB 的 UTF-8 CSV');
        $csv = preg_replace('/^\xEF\xBB\xBF/','',$value) ?? $value;
        $stream = fopen('php://temp','w+'); fwrite($stream,$csv); rewind($stream);
        $errors = []; $warnings = []; $rows = [];
        try {
            $header = fgetcsv($stream,0,',','"','');
            if (!$header || count($header)>12) throw new ApiError('CSV 缺少有效表头或列数过多');
            $aliases = ['班级'=>'class_name','学号'=>'student_no','姓名'=>'name','楼栋'=>'building','房间号'=>'room','寝室长学号'=>'leader_no','寝室长姓名'=>'leader_name','room_number'=>'room'];
            $fields = array_map(fn($h)=>$aliases[trim((string)$h)] ?? trim((string)$h),$header);
            if (count(array_unique($fields))!==count($fields)) throw new ApiError('CSV 表头不能重复');
            foreach (['student_no','name','building','room','leader_no'] as $required) if (!in_array($required,$fields,true)) throw new ApiError('CSV 缺少列：'.$required);
            foreach ($fields as $f) if (!in_array($f,['class_name','student_no','name','building','room','leader_no','leader_name'],true)) throw new ApiError('CSV 含有不支持的列：'.$f);
            $seen = []; $names = []; $leaders = []; $line = 1;
            while (($cells=fgetcsv($stream,0,',','"',''))!==false) {
                $line++; if ($line>1101) throw new ApiError('CSV 行数过多');
                if ($cells===[null] || (count($cells)===1 && trim((string)$cells[0])==='')) continue;
                if (count($rows)>=1000) throw new ApiError('名单最多导入 1,000 人');
                if (count($cells)!==count($fields)) { $errors[]="第 {$line} 行列数与表头不符"; continue; }
                $raw = array_combine($fields,$cells); $r = [];
                try {
                    foreach (['student_no'=>64,'name'=>100,'building'=>100,'room'=>100,'leader_no'=>64] as $key=>$max) {
                        $r[$key] = Support::text($raw[$key] ?? '',$max); if ($r[$key]==='') throw new ApiError('必填列不能为空：'.$key);
                    }
                    $r['class_name'] = Support::text($raw['class_name'] ?? '',100) ?: $class['name'];
                    if ($r['class_name']!==$class['name']) throw new ApiError('名单班级与选定班级不一致');
                    foreach (['student_no','leader_no'] as $key) if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/D',$r[$key])) throw new ApiError('学号仅支持 3—64 位字母、数字、下划线及短横线');
                    if (isset($seen[$r['student_no']])) throw new ApiError('重复学号：'.$r['student_no']);
                    $seen[$r['student_no']]=true;
                    $roomKey=$r['building']."\0".$r['room'];
                    if (isset($leaders[$roomKey]) && $leaders[$roomKey]!==$r['leader_no']) throw new ApiError('同一寝室的负责人学号不一致');
                    $leaders[$roomKey]=$r['leader_no'];
                    if (isset($names[$r['name']])) $warnings[]='存在同名学生，请按学号区分：'.$r['name'];
                    $names[$r['name']]=true;
                    $rows[]=$r;
                } catch (ApiError $e) { $errors[]="第 {$line} 行：".$e->getMessage(); }
                if ($line>1100) throw new ApiError('CSV 行数过多');
            }
            $known=[];
            foreach (Support::all($this->db,'SELECT s.student_no,rm.building,rm.room_number FROM students s JOIN rooms rm ON rm.id=s.room_id WHERE s.active=1') as $s) $known[$s['student_no']]=[$s['building'],$s['room_number']];
            foreach ($rows as $r) $known[$r['student_no']]=[$r['building'],$r['room']];
            foreach ($rows as $i=>$r) {
                $student=Support::one($this->db,'SELECT class_name FROM students WHERE student_no=?',[$r['student_no']]);
                if ($student && Grade::classId($this->db,$student['class_name'])!==(int)$class['id']) {
                    if (!Grade::includes($this->db,Grade::scope($this->db,$u),$student['class_name'])) $errors[]='第 '.($i+2).' 行无法导入，请联系管理员核实学号';
                    else $warnings[]='存在转班学生，确认导入将改变其后续班级；历史记录不变。';
                }
                $other=Support::all($this->db,'SELECT s.student_no,s.leader_no FROM students s JOIN rooms rm ON rm.id=s.room_id WHERE s.active=1 AND rm.building=? AND rm.room_number=?',[$r['building'],$r['room']]);
                foreach ($other as $existing) if (!isset($seen[$existing['student_no']]) && $existing['leader_no']!==$r['leader_no']) $errors[]='第 '.($i+2).' 行宿舍负责人与已有名单冲突，请联系管理员核实';
                if (!isset($known[$r['leader_no']]) || $known[$r['leader_no']]!==[$r['building'],$r['room']]) $warnings[]='存在未导入或不在本宿舍的负责人；核实并补齐前，该宿舍无法便捷登记。';
                $old=Support::one($this->db,'SELECT s.leader_no,rm.building,rm.room_number FROM students s JOIN rooms rm ON rm.id=s.room_id WHERE s.student_no=? AND s.active=1',[$r['student_no']]);
                if ($old && $old['leader_no']===$r['student_no'] && ($old['building']!==$r['building'] || $old['room_number']!==$r['room'] || $r['leader_no']!==$r['student_no'])) {
                    foreach (Support::all($this->db,'SELECT student_no FROM students WHERE active=1 AND leader_no=? AND student_no<>?',[$r['student_no'],$r['student_no']]) as $dependent) if (!isset($seen[$dependent['student_no']])) $errors[]='负责人变动影响未列入名单的宿舍成员，请先由管理员完成交接';
                }
            }
            if (!$rows && !$errors) $errors[]='名单中没有数据行';
            if ($rows) $warnings[]='未出现在本次文件中的现有学生不会自动删除；已冻结的当晚名册不会被导入覆盖。';
        } finally { fclose($stream); }
        $changes=['added'=>0,'updated'=>0,'unchanged'=>0,'conflict'=>0];
        $userScope=Grade::scope($this->db,$u);
        foreach ($rows as &$row) {
            $old=Support::one($this->db,'SELECT s.*,rm.building,rm.room_number FROM students s JOIN rooms rm ON rm.id=s.room_id WHERE s.student_no=?',[$row['student_no']]);
            $same=$old && $old['name']===$row['name'] && $old['class_name']===$row['class_name'] && $old['building']===$row['building'] && $old['room_number']===$row['room'] && $old['leader_no']===$row['leader_no'] && (int)$old['active']===1;
            $row['operation']=$old && !Grade::includes($this->db,$userScope,$old['class_name'])?'conflict':(!$old?'added':($same?'unchanged':'updated')); $changes[$row['operation']]++;
        }
        unset($row);
        return ['rows'=>$rows,'errors'=>$errors,'warnings'=>array_values(array_unique($warnings)),'count'=>count($rows),'changes'=>$changes];
    }

    private function previewHash(string $csv, int $actor, int $classId): string
    {
        $roster=Support::all($this->db,'SELECT student_no,name,class_name,room_id,leader_no,active,updated_at FROM students ORDER BY student_no');
        return hash_hmac('sha256',$actor."\n".$classId."\n".Support::json($roster)."\n".$csv,(string)$this->config['app_key']);
    }

    private function importPreview(array $u, array $data): array
    {
        $this->manager($u); $class=Grade::requireClass($this->db,$u,$this->positive($data['class_id'] ?? 0));
        $result=$this->parseCsv($data['csv'] ?? null,$class,$u);
        return $result+['hash'=>$this->previewHash($data['csv'],$u['id'],(int)$class['id'])];
    }

    private function importCommit(array $u, array $data): array
    {
        $this->manager($u); $class=Grade::requireClass($this->db,$u,$this->positive($data['class_id'] ?? 0));
        $parsed=$this->parseCsv($data['csv'] ?? null,$class,$u);
        if (($data['confirm'] ?? null)!==true || !is_string($data['hash'] ?? null) || !hash_equals($this->previewHash($data['csv'],$u['id'],(int)$class['id']),$data['hash'])) throw new ApiError('请重新预览并确认这份名单', 'PREVIEW_REQUIRED',409);
        if ($parsed['errors']) throw new ApiError('名单仍有问题，不能导入');
        $added=0; $updated=0;
        foreach ($parsed['rows'] as $r) {
            $room=Support::one($this->db,'SELECT id FROM rooms WHERE building=? AND room_number=?',[$r['building'],$r['room']]);
            if (!$room) { Support::exec($this->db,'INSERT INTO rooms (building,room_number) VALUES (?,?)',[$r['building'],$r['room']]); $room=['id'=>(int)$this->db->lastInsertId()]; }
            $student=Support::one($this->db,'SELECT * FROM students WHERE student_no=?',[$r['student_no']]);
            if ($student && $student['name']===$r['name'] && $student['class_name']===$r['class_name'] && (int)$student['room_id']===(int)$room['id'] && $student['leader_no']===$r['leader_no'] && (int)$student['active']===1) continue;
            if ($student) {
                Support::exec($this->db,'UPDATE students SET name=?,class_name=?,room_id=?,leader_no=?,active=1,updated_at=? WHERE id=?',[$r['name'],$r['class_name'],$room['id'],$r['leader_no'],$this->now(),$student['id']]);
                Support::exec($this->db,'UPDATE placements SET valid_to=? WHERE student_id=? AND valid_to IS NULL',[$this->now(),$student['id']]); $id=(int)$student['id']; $updated++;
            } else {
                Support::exec($this->db,'INSERT INTO students (student_no,name,class_name,room_id,leader_no,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',[$r['student_no'],$r['name'],$r['class_name'],$room['id'],$r['leader_no'],$this->now(),$this->now()]); $id=(int)$this->db->lastInsertId(); $added++;
            }
            Support::exec($this->db,'INSERT INTO placements (student_id,room_id,student_no,name,class_name,leader_no,valid_from) VALUES (?,?,?,?,?,?,?)',[$id,$room['id'],$r['student_no'],$r['name'],$r['class_name'],$r['leader_no'],$this->now()]);
        }
        $this->prepare(substr($this->now(),0,10));
        Audit::write($this->db,$u['id'],'roster.import',['count'=>$parsed['count'],'added'=>$added,'updated'=>$updated]);
        return ['count'=>$parsed['count'],'added'=>$added,'updated'=>$updated,'message'=>'已保存，冻结后的名册不变'];
    }

    private function retireStudent(array $u, array $data): array
    {
        $this->manager($u); $id=$this->positive($data['student_id'] ?? 0); $reason=Support::text($data['reason'] ?? '',500);
        if ($reason==='') throw new ApiError('移出当前名册必须填写核实原因');
        $s=Support::one($this->db,'SELECT * FROM students WHERE id=? AND active=1',[$id]);
        if (!$s) throw new ApiError('有效名册中没有此学生', 'NOT_FOUND',404);
        $this->scopedMember($s);
        if (Support::one($this->db,'SELECT id FROM students WHERE leader_no=? AND active=1 AND id<>? LIMIT 1',[$s['student_no'],$id])) throw new ApiError('此同学仍被其他学生列为寝室负责人，请先导入核实后的负责人关系');
        Support::exec($this->db,'UPDATE students SET active=0,updated_at=? WHERE id=?',[$this->now(),$id]);
        Support::exec($this->db,'UPDATE placements SET valid_to=? WHERE student_id=? AND valid_to IS NULL',[$this->now(),$id]);
        $this->prepare(substr($this->now(),0,10));
        Audit::write($this->db,$u['id'],'student.retire',['student_id'=>$id,'reason'=>$reason]);
        return ['message'=>'已移出当前名册；已冻结的旧晚记录和账号授权未自动删除，请另行核实处理'];
    }

    private function exemptionSave(array $u, array $data): array
    {
        $this->manager($u); $student=$this->positive($data['student_id'] ?? 0);
        $studentRow=Support::one($this->db,'SELECT id,class_name FROM students WHERE id=? AND active=1',[$student]);
        if (!$studentRow) throw new ApiError('学生不存在');
        $this->scopedMember($studentRow);
        $start=$this->date($data['start_date'] ?? ''); $end=$this->date($data['end_date'] ?? '');
        $source=Support::text($data['source'] ?? '',200); $note=Support::text($data['note'] ?? '',500);
        if ($end<$start || $source==='' || ($data['approved'] ?? null)!==true) throw new ApiError('请核实有效日期、许可来源并明确确认');
        Support::exec($this->db,'INSERT INTO exemptions (student_id,start_date,end_date,source,note,actor_id,created_at) VALUES (?,?,?,?,?,?,?)',[$student,$start,$end,$source,$note,$u['id'],$this->now()]); $id=(int)$this->db->lastInsertId();
        Audit::write($this->db,$u['id'],'exemption.save',['id'=>$id,'student_id'=>$student,'start_date'=>$start,'end_date'=>$end,'source'=>$source]);
        return ['exemption'=>Support::one($this->db,'SELECT * FROM exemptions WHERE id=?',[$id])];
    }

    private function exemptionRevoke(array $u, array $data): array
    {
        $this->manager($u); $id=$this->positive($data['id'] ?? 0); $reason=Support::text($data['reason'] ?? '',500);
        if ($reason==='') throw new ApiError('撤销必须填写原因');
        $e=Support::one($this->db,'SELECT * FROM exemptions WHERE id=? AND revoked_at IS NULL',[$id]);
        if (!$e) throw new ApiError('安排不存在或已经撤销', 'NOT_FOUND',404);
        $studentRow=Support::one($this->db,'SELECT class_name FROM students WHERE id=?',[$e['student_id']]);
        if (!$studentRow) throw new ApiError('学生不存在');
        $this->scopedMember($studentRow);
        if ($this->scope!==null) {
            foreach (Support::all($this->db,'SELECT m.class_name FROM night_members m JOIN night_tasks n ON n.id=m.task_id WHERE m.student_id=? AND n.business_date>=? AND n.business_date<=?',[$e['student_id'],$e['start_date'],$e['end_date']]) as $history) $this->scopedMember($history);
        }
        Support::exec($this->db,'UPDATE exemptions SET revoked_at=?,revoke_reason=? WHERE id=?',[$this->now(),$reason,$id]);
        $members=Support::all($this->db,"SELECT m.*,n.business_date FROM night_members m JOIN night_tasks n ON n.id=m.task_id JOIN room_tasks r ON r.task_id=m.task_id AND r.room_id=m.room_id WHERE m.student_id=? AND m.included=1 AND m.current_status='exempt' AND r.phase='open' AND n.business_date>=? AND n.business_date<=?",[$e['student_id'],$e['start_date'],$e['end_date']]);
        foreach ($members as $m) {
            if ($this->validExemption((int)$m['student_id'],$m['business_date'])) continue;
            $location='返寝例外已撤销，待重新核实';
            Support::exec($this->db,"UPDATE night_members SET current_status='unknown',location=?,return_at=NULL,version=version+1,updated_at=? WHERE task_id=? AND student_id=?",[$location,$this->now(),$m['task_id'],$m['student_id']]);
            Support::exec($this->db,'UPDATE room_tasks SET report_version=report_version+1,updated_at=? WHERE task_id=? AND room_id=?',[$this->now(),$m['task_id'],$m['room_id']]);
            $this->event($u,$m,(int)$m['student_id'],'exemption_revoke',$m,['current_status'=>'unknown','location'=>$location],$reason);
        }
        Audit::write($this->db,$u['id'],'exemption.revoke',['id'=>$id,'reason'=>$reason]); return ['message'=>'安排已撤销，未结项的无需返寝状态已转为待核实'];
    }

    private function activityList(string $action, array $u, array $data): array
    {
        $this->manager($u); $date=$this->date($data['date'] ?? $this->businessDate());
        $items=Support::all($this->db,"SELECT a.*,r.building,r.room_number FROM $action a JOIN night_tasks n ON n.id=a.task_id JOIN room_tasks r ON r.task_id=a.task_id AND r.room_id=a.room_id WHERE n.business_date=? ORDER BY a.id",[$date]);
        if ($this->scope!==null) {
            $items=array_values(array_filter($items,function ($item) use ($action): bool {
                if ($action==='followups') {
                    $member=Support::one($this->db,'SELECT class_name FROM night_members WHERE task_id=? AND student_id=?',[$item['task_id'],$item['student_id']]);
                    return $member && $this->inScope($member['class_name']);
                }
                $members=Support::all($this->db,'SELECT class_name FROM night_members WHERE task_id=? AND room_id=? AND included=1',[$item['task_id'],$item['room_id']]);
                if (!$members) return false;
                foreach ($members as $member) if (!$this->inScope($member['class_name'])) return false;
                return true;
            }));
        }
        foreach ($items as &$item) { $item['task_id']=$item['task_id'].'.'.$item['room_id']; $item['room']=$item['building'].' · '.$item['room_number']; }
        unset($item); return ['items'=>$items];
    }

    private function activitySave(string $action, array $u, array $data): array
    {
        $this->manager($u); $t=$this->task($u,$data['task_id'] ?? '');
        if (in_array($t['phase'],['closed','paused'],true)) throw new ApiError('请先重新开放任务再登记', 'TASK_CLOSED',409);
        $note=Support::text($data['note'] ?? '',1000); if ($note==='') throw new ApiError('请填写本次核实情况');
        if ($action==='followup_save') {
            $m=$this->member($t,$data['student_id'] ?? 0); $state=$data['state'] ?? '';
            if (!in_array($state,['pending','contacted','safe','waiting','closed'],true)) throw new ApiError('联系进度无效');
            Support::exec($this->db,'INSERT INTO followups (task_id,room_id,student_id,state,note,actor_id,actor_name,created_at) VALUES (?,?,?,?,?,?,?,?)',[$t['task_id'],$t['room_id'],$m['student_id'],$state,$note,$u['id'],$u['name'],$this->now()]); $table='followups';
        } else {
            $this->fullRoom($t);
            if (isset($data['room_id']) && (int)$data['room_id']!==(int)$t['room_id']) throw new ApiError('寝室与任务不匹配');
            $result=Support::text($data['result'] ?? '',100); if ($result==='') throw new ApiError('请填写抽查结果');
            Support::exec($this->db,'INSERT INTO inspections (task_id,room_id,result,note,actor_id,actor_name,created_at) VALUES (?,?,?,?,?,?,?)',[$t['task_id'],$t['room_id'],$result,$note,$u['id'],$u['name'],$this->now()]); $table='inspections';
        }
        $id=(int)$this->db->lastInsertId(); Audit::write($this->db,$u['id'],$action,['id'=>$id,'task_id'=>$data['task_id']]);
        return ['item'=>Support::one($this->db,"SELECT * FROM $table WHERE id=?",[$id])];
    }

    private function getSettings(array $u): array
    {
        $this->manager($u,true);
        return ['settings'=>$this->settingsValues(),'job_runs'=>Support::all($this->db,'SELECT * FROM job_runs ORDER BY id DESC LIMIT 20')];
    }

    private function saveSettings(array $u, array $data): array
    {
        $this->manager($u,true); $this->recent($u); $s=$data['settings'] ?? null;
        if (!is_array($s)) throw new ApiError('设置格式不正确');
        $allowed=['grade_name','check_time','due_time','reminder_time','paused_dates','retention_days','totp_student_no','filing_icp','filing_police'];
        foreach ($s as $key=>$v) {
            if (!in_array($key,$allowed,true)) throw new ApiError('不支持的设置项：'.$key);
            if (in_array($key,['check_time','due_time','reminder_time'],true)) {
                if (!is_string($v) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$v)) throw new ApiError('时间请使用 HH:mm 格式');
            } elseif ($key==='grade_name') { $v=Support::text($v,100); if ($v==='') throw new ApiError('年级名称不能为空'); }
            elseif (in_array($key,['filing_icp','filing_police'],true)) {
                if (!is_string($v)) throw new ApiError('备案号必须为文字');
                $v=Support::text($v,100);
                if ($key==='filing_police' && $v!=='' && !preg_match('/^[\p{Han}]{1,10}公网安备[0-9]{8,30}号$/uD',$v)) throw new ApiError('公安备案号请填写完整文字，例如：京公网安备11000000000000号');
            }
            elseif ($key==='totp_student_no') {
                if (!is_string($v) || !preg_match('/^[A-Za-z0-9_-]{3,64}$/D',trim($v))) throw new ApiError('请填写一个完整的登录学号，不能留空');
                $target=Support::one($this->db,'SELECT id,student_no,status FROM users WHERE student_no=?',[trim($v)]);
                if (!$target || $target['status']!=='active') throw new ApiError('指定学号必须是已经激活且未停用的登录账号');
                $v=$target['student_no'];
            }
            elseif ($key==='paused_dates') {
                if (!is_array($v) || !array_is_list($v) || count($v)>366) throw new ApiError('暂停日期列表格式不正确');
                $v=array_values(array_unique(array_map(fn($d)=>$this->date($d),$v))); sort($v);
            } else {
                if (filter_var($v,FILTER_VALIDATE_INT)===false || (int)$v<1 || (int)$v>3650) throw new ApiError('保留天数应为 1—3650');
                $v=(int)$v;
            }
            $s[$key]=$v;
        }
        $before=$this->settingsValues(); $after=array_replace($before,$s);
        $totpChanged=$before['totp_student_no']!==$after['totp_student_no'];
        if ($totpChanged) {
            if (!array_key_exists('totp_previous_student_no',$data) || $data['totp_previous_student_no']!==$before['totp_student_no']) throw new ApiError('两步验证设置已变化，请刷新后重新核对','MFA_POLICY_CONFLICT',409);
            if (($data['confirm_totp_change'] ?? false)!==true) throw new ApiError('请确认仅保留指定学号的两步验证，并让所有账号重新登录','MFA_POLICY_CONFIRM_REQUIRED',409);
        }
        if ($after['reminder_time']>$after['check_time'] || $after['check_time']>$after['due_time']) throw new ApiError('提醒、查寝和截止时间必须按顺序设置，首版截止时间应在同一天');
        foreach ($s as $key=>$v) Schema::set($this->db,$key,$v);
        if ($totpChanged) {
            $target=Support::one($this->db,'SELECT id FROM users WHERE student_no=?',[$after['totp_student_no']]);
            $disabled=Support::all($this->db,'SELECT id FROM users WHERE student_no<>? AND totp_enabled=1',[$after['totp_student_no']]);
            Support::exec($this->db,'UPDATE users SET totp_secret=NULL,totp_enabled=0,totp_last_step=-1 WHERE student_no<>?',[$after['totp_student_no']]);
            Support::exec($this->db,'DELETE FROM recovery_codes WHERE user_id<>?',[$target['id']]);
            Support::exec($this->db,'UPDATE users SET auth_version=auth_version+1,updated_at=?',[$this->now()]);
            Audit::write($this->db,$u['id'],'settings.totp_account',['before'=>$before['totp_student_no'],'after'=>$after['totp_student_no'],'disabled_user_ids'=>array_column($disabled,'id'),'all_sessions_revoked'=>true]);
        }
        if (array_intersect(['check_time','due_time','paused_dates'],array_keys($s))) {
            foreach (Support::all($this->db,'SELECT * FROM night_tasks WHERE frozen_at IS NULL') as $n) {
                $phase=in_array($n['business_date'],$after['paused_dates'],true)?'paused':'draft';
                Support::exec($this->db,'UPDATE night_tasks SET check_at=?,due_at=?,phase=?,updated_at=? WHERE id=?',[$n['business_date'].' '.$after['check_time'].':00',$n['business_date'].' '.$after['due_time'].':00',$phase,$this->now(),$n['id']]);
                Support::exec($this->db,'UPDATE room_tasks SET phase=? WHERE task_id=?',[$phase,$n['id']]);
            }
        }
        Audit::write($this->db,$u['id'],'settings.save',['before'=>$before,'after'=>$after]);
        return ['settings'=>$after,'relogin_required'=>$totpChanged];
    }

    private function audit(array $u, array $data): array
    {
        $this->manager($u,true); $page=max(1,min(100000,(int)($data['page'] ?? 1))); $offset=($page-1)*50;
        $items=Support::all($this->db,"SELECT a.*,u.name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 50 OFFSET $offset");
        foreach ($items as &$item) { $item['detail']=json_decode($item['detail_json'],true,512,JSON_THROW_ON_ERROR); unset($item['detail_json']); }
        unset($item); return ['items'=>$items,'page'=>$page];
    }

    private function report(array $u, array $data): array
    {
        $this->manager($u); $date=$this->date($data['date'] ?? $this->businessDate());
        return Reports::scoped($this->db,Reports::load($this->db,$date,$data['version'] ?? 'latest',$this->now()),$this->scope);
    }

    private function snapshot(array $u, array $data): array
    {
        $this->manager($u); $date=$this->date($data['date'] ?? $this->businessDate());
        $reason=Support::text($data['reason'] ?? '班委手动保存',500);
        $result=Reports::save($this->db,$date,$this->now(),$reason);
        Audit::write($this->db,$u['id'],'report.snapshot',['date'=>$date,'version'=>$result['version'],'reason'=>$reason]); return Reports::scoped($this->db,$result,$this->scope);
    }

    private function export(array $u, array $data): array
    {
        $this->manager($u); $this->recent($u); $report=$this->report($u,$data);
        if (!$report['people']) throw new ApiError('该晚没有名册，不能导出空白日报');
        $directory=$this->config['storage_path'].'/exports';
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new \RuntimeException('Cannot create private export directory');
        $path=$directory.'/report-'.bin2hex(random_bytes(16)).'.xlsx';
        try { Xlsx::write(Reports::sheets($report),$path); }
        catch (\Throwable $e) { if (is_file($path)) unlink($path); throw $e; }
        Audit::write($this->db,$u['id'],'report.export',['date'=>$report['business_date'],'version'=>$report['version'],'as_of'=>$report['as_of'],'count'=>count($report['people'])]);
        return ['download_path'=>$path,'filename'=>'晚查寝_'.$report['business_date'].'_v'.$report['version'].'.xlsx'];
    }

    private function syncNightPhase(int $id): void
    {
        $counts=Support::one($this->db,"SELECT COUNT(*) AS total,SUM(CASE WHEN phase='closed' THEN 1 ELSE 0 END) AS closed FROM room_tasks WHERE task_id=?",[$id]);
        if ((int)$counts['total']>0) Support::exec($this->db,'UPDATE night_tasks SET phase=?,updated_at=? WHERE id=?',[(int)$counts['closed']===(int)$counts['total']?'closed':'open',$this->now(),$id]);
    }

    private function closeTask(string $action, array $u, array $data): array
    {
        $this->manager($u); $t=$this->task($u,$data['task_id'] ?? '',true); $this->fullRoom($t); $reason=Support::text($data['reason'] ?? '',500);
        if ($reason==='') throw new ApiError('请填写操作原因');
        if ($action==='task_close') {
            if ($t['phase']!=='open' || !$t['submitted_at']) throw new ApiError('只有完成初报的开放任务可以结项', 'TASK_NOT_READY',409);
            $unresolved=Support::one($this->db,"SELECT COUNT(*) AS n FROM night_members WHERE task_id=? AND room_id=? AND included=1 AND current_status NOT IN ('present','exempt')",[$t['task_id'],$t['room_id']]);
            if ((int)$unresolved['n']>0) throw new ApiError('尚有未归、缺报或待核实人员，不能结项', 'UNRESOLVED_MEMBERS',409);
            $phase='closed';
        } else {
            if ($t['phase']!=='closed') throw new ApiError('当前任务不是已结项状态', 'TASK_NOT_CLOSED',409);
            $phase='open';
        }
        Support::exec($this->db,'UPDATE room_tasks SET phase=?,closed_at=?,report_version=report_version+1,updated_at=? WHERE task_id=? AND room_id=?',[$phase,$phase==='closed'?$this->now():null,$this->now(),$t['task_id'],$t['room_id']]);
        $this->event($u,$t,null,$action,['phase'=>$t['phase']],['phase'=>$phase],$reason);
        $this->syncNightPhase((int)$t['task_id']);
        Reports::save($this->db,$t['business_date'],$this->now(),($phase==='closed'?'结项归档：':'重新开放：').$reason);
        Audit::write($this->db,$u['id'],$action,['task_id'=>$data['task_id'],'reason'=>$reason]);
        return ['task'=>$this->roomPayload($this->task($u,$data['task_id']))];
    }

    private function adjustRoster(array $u, array $data): array
    {
        $this->manager($u); $t=$this->task($u,$data['task_id'] ?? '',true); $this->fullRoom($t); $reason=Support::text($data['reason'] ?? '',500);
        if (!$t['frozen_at'] || $t['phase']!=='open') throw new ApiError('只可调整已冻结、未结项的任务；结项后请先重新开放');
        if ($reason==='') throw new ApiError('调整当晚名册必须说明原因');
        if ((string)($data['roster_version'] ?? '')!==(string)$t['roster_version']) throw new ApiError('名册已变化，请重新核对', 'ROSTER_CONFLICT',409);
        $ids=$data['student_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids)>1000) throw new ApiError('名册格式不正确');
        $ids=array_map(fn($id)=>$this->positive($id),$ids);
        if (count(array_unique($ids))!==count($ids)) throw new ApiError('不能重复选择成员');
        $before=Support::all($this->db,'SELECT student_id FROM night_members WHERE task_id=? AND room_id=? AND included=1 ORDER BY student_id',[$t['task_id'],$t['room_id']]);
        $old=array_map(fn($r)=>(int)$r['student_id'],$before);
        foreach ($ids as $id) {
            $s=Support::one($this->db,'SELECT * FROM students WHERE id=? AND active=1',[$id]);
            if (!$s) throw new ApiError('所选学生不在有效名册中');
            $this->scopedMember($s);
            $m=Support::one($this->db,'SELECT * FROM night_members WHERE task_id=? AND student_id=?',[$t['task_id'],$id]);
            if ($m && (int)$m['included']===1 && (int)$m['room_id']!==(int)$t['room_id']) throw new ApiError('该同学仍在另一寝室的当晚名册中，请先带理由从原寝移除，再加入目标寝室');
            if (!$m) {
                $e=$this->validExemption($id,$t['business_date']);
                Support::exec($this->db,'INSERT INTO night_members (task_id,room_id,student_id,student_no,name,class_name,exemption_json,initial_location,location,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$t['task_id'],$t['room_id'],$id,$s['student_no'],$s['name'],$s['class_name'],$e?Support::json($e):null,'','',$this->now()]);
            } elseif (!in_array($id,$old,true)) {
                Support::exec($this->db,'UPDATE night_members SET room_id=?,included=1,updated_at=?,version=version+1 WHERE task_id=? AND student_id=?',[$t['room_id'],$this->now(),$t['task_id'],$id]);
            }
        }
        foreach ($old as $id) if (!in_array($id,$ids,true)) Support::exec($this->db,'UPDATE night_members SET included=0,updated_at=?,version=version+1 WHERE task_id=? AND student_id=?',[$this->now(),$t['task_id'],$id]);
        Support::exec($this->db,'UPDATE room_tasks SET roster_version=roster_version+1,report_version=report_version+1,updated_at=? WHERE task_id=? AND room_id=?',[$this->now(),$t['task_id'],$t['room_id']]);
        Support::exec($this->db,'UPDATE night_tasks SET roster_version=roster_version+1,updated_at=? WHERE id=?',[$this->now(),$t['task_id']]);
        if (!$ids) Support::exec($this->db,"UPDATE room_tasks SET phase='closed',closed_at=? WHERE task_id=? AND room_id=?",[$this->now(),$t['task_id'],$t['room_id']]);
        $this->event($u,$t,null,'roster_adjust',['student_ids'=>$old],['student_ids'=>$ids],$reason);
        $this->syncNightPhase((int)$t['task_id']);
        Audit::write($this->db,$u['id'],'roster.adjust',['task_id'=>$data['task_id'],'before'=>$old,'after'=>$ids,'reason'=>$reason]);
        return ['task'=>$this->roomPayload($this->task($u,$data['task_id'])),'message'=>'名册版本已更新；初报之后新增的未登记成员请由班委带理由更正初报及当前状态'];
    }

    private function draft(string $action, array $u, array $data): array
    {
        $t=$this->task($u,$data['task_id'] ?? '');
        if ($action==='draft_get') {
            $d=Support::one($this->db,'SELECT * FROM drafts WHERE task_id=? AND room_id=? AND actor_id=?',[$t['task_id'],$t['room_id'],$u['id']]);
            if (!$d) return ['draft'=>null];
            $d['members']=json_decode($d['content_json'],true,512,JSON_THROW_ON_ERROR); unset($d['content_json']);
            if ($this->scope!==null) {
                $allowed=array_column($this->roomPayload($t)['members'],'student_id');
                $d['members']=array_values(array_filter($d['members'],fn($m)=>in_array((int)($m['student_id'] ?? 0),$allowed,true)));
            }
            $d['stale']=(int)$d['roster_version']!==(int)$t['roster_version']; return ['draft'=>$d];
        }
        if ($t['submitted_at'] || in_array($t['phase'],['closed','paused'],true)) throw new ApiError('已初报、结项或暂停的任务不能保存初报草稿');
        if ((string)($data['roster_version'] ?? '')!==(string)$t['roster_version']) throw new ApiError('名册已变化，请重新核对', 'ROSTER_CONFLICT',409);
        $rows=$data['members'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>1000) throw new ApiError('草稿成员格式不正确');
        $clean=[]; $seen=[];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new ApiError('草稿成员格式不正确');
            $m=$this->member($t,$row['student_id'] ?? 0); $id=(int)$m['student_id'];
            if (isset($seen[$id])) throw new ApiError('草稿成员不能重复'); $seen[$id]=true;
            $status=$row['status'] ?? ''; $current=$row['current_status'] ?? '';
            $preservedUnreported=$status==='unreported' && $m['initial_status']==='unreported' && $m['corrected_initial_status']!==null;
            if (!is_string($status) || ($status!=='' && !in_array($status,self::STATUSES,true) && !$preservedUnreported)) throw new ApiError('草稿状态格式不正确');
            if (!is_string($current) || ($current!=='' && !in_array($current,self::STATUSES,true))) throw new ApiError('草稿状态格式不正确');
            $clean[]=['student_id'=>$id,'status'=>$status,'current_status'=>$current,'location'=>Support::text($row['location'] ?? '',200),'current_location'=>Support::text($row['current_location'] ?? '',200),'expected_return_at'=>empty($row['expected_return_at'])?null:$this->datetime($row['expected_return_at']),'occurred_at'=>empty($row['occurred_at'])?null:$this->datetime($row['occurred_at'])];
        }
        Support::exec($this->db,'DELETE FROM drafts WHERE task_id=? AND room_id=? AND actor_id=?',[$t['task_id'],$t['room_id'],$u['id']]);
        Support::exec($this->db,'INSERT INTO drafts (task_id,room_id,actor_id,roster_version,content_json,updated_at) VALUES (?,?,?,?,?,?)',[$t['task_id'],$t['room_id'],$u['id'],$t['roster_version'],Support::json($clean),$this->now()]);
        return ['message'=>'草稿已保存，尚未正式提交','updated_at'=>$this->now()];
    }

    public function tick(): array
    {
        $started=$this->now(); $today=substr($started,0,10); $cursor=$this->date(Schema::get($this->db,'scheduler_cursor',$today)); $processed=[];
        try {
            for ($count=0; $cursor<=$today && $count<366; $count++) {
                $date=$cursor;
                $processed[]=Schema::transaction($this->db,function () use ($date): array {
                    $this->prepare($date); $n=Support::one($this->db,'SELECT * FROM night_tasks WHERE business_date=?',[$date]); $saved=null;
                    if ($n['phase']!=='paused' && $this->now()>=$n['due_at'] && !Support::one($this->db,'SELECT id FROM daily_reports WHERE auto_key=?',['due:'.$date])) {
                        $late=$this->now()>date('Y-m-d H:i:s',strtotime($n['due_at'])+60);
                        $saved=Reports::save($this->db,$date,$this->now(),$late?'自动首报：计划任务延迟，数据截至实际生成时点':'自动首报','due:'.$date);
                    }
                    Schema::set($this->db,'scheduler_cursor',$date);
                    return ['date'=>$date,'phase'=>$n['phase'],'saved_version'=>$saved['version'] ?? null];
                });
                $cursor=(new \DateTimeImmutable($cursor,new \DateTimeZone('Asia/Shanghai')))->modify('+1 day')->format('Y-m-d');
            }
            $more=$cursor<=$today;
            if ($more) Schema::transaction($this->db,fn()=>Schema::set($this->db,'scheduler_cursor',$cursor));
            $this->cleanExports();
            Support::exec($this->db,'INSERT INTO job_runs (job_name,business_date,status,message,started_at,finished_at) VALUES (?,?,?,?,?,?)',['tick',$today,'success',Support::json(['dates'=>count($processed),'catchup_pending'=>$more]),$started,$this->now()]);
            return ['ok'=>true,'processed'=>$processed,'catchup_pending'=>$more,'server_time'=>$this->now()];
        } catch (\Throwable $e) {
            Support::exec($this->db,'INSERT INTO job_runs (job_name,business_date,status,message,started_at,finished_at) VALUES (?,?,?,?,?,?)',['tick',$today,'failed','调度失败，请由维护人员检查运行日志',$started,$this->now()]);
            throw $e;
        }
    }

    private function cleanExports(): void
    {
        $path=$this->config['storage_path'].'/exports';
        if (!is_dir($path)) return;
        foreach (glob($path.'/report-*.xlsx') ?: [] as $file) if (is_file($file) && !is_link($file) && filemtime($file)<time()-900) unlink($file);
    }

    public function prune(string $before): array
    {
        $before=$this->date($before);
        if ($before>=substr($this->now(),0,10)) throw new ApiError('不能清理今天或未来的记录');
        return Schema::transaction($this->db,function () use ($before): array {
            $nights=Support::all($this->db,'SELECT id FROM night_tasks WHERE business_date<?',[$before]);
            foreach ($nights as $n) {
                foreach (['attendance_events','night_members','room_tasks','daily_reports','followups','inspections','drafts'] as $table) Support::exec($this->db,"DELETE FROM $table WHERE task_id=?",[$n['id']]);
                Support::exec($this->db,'DELETE FROM grants WHERE task_id=?',[$n['id']]);
                Support::exec($this->db,'DELETE FROM night_tasks WHERE id=?',[$n['id']]);
            }
            foreach (['request_keys'=>'created_at','job_runs'=>'finished_at','audit_logs'=>'created_at'] as $table=>$column) Support::exec($this->db,"DELETE FROM $table WHERE $column<?",[$before.' 00:00:00']);
            Support::exec($this->db,'DELETE FROM drafts WHERE updated_at<?',[$before.' 00:00:00']);
            Support::exec($this->db,'DELETE FROM placements WHERE valid_to IS NOT NULL AND valid_to<?',[$before.' 00:00:00']);
            Support::exec($this->db,'DELETE FROM exemptions WHERE end_date<?',[$before]);
            $cursor=Schema::get($this->db,'scheduler_cursor',substr($this->now(),0,10));
            if ($cursor<$before) Schema::set($this->db,'scheduler_cursor',$before);
            Audit::write($this->db,null,'maintenance.prune',['before'=>$before,'nights'=>count($nights)]);
            return ['before'=>$before,'nights'=>count($nights)];
        });
    }

    public function seedDemo(): array
    {
        if (($this->config['env'] ?? '')!=='development') throw new ApiError('仅 development 环境允许虚构演示数据');
        if ((int)Support::one($this->db,'SELECT COUNT(*) AS n FROM students')['n']>0 || (int)Support::one($this->db,'SELECT COUNT(*) AS n FROM users')['n']>0) throw new ApiError('演示初始化只允许空数据库');
        $auth=new Auth($this->db,$this->config); $accounts=[];
        foreach ([['00990001','虚构管理员','admin'],['00990002','虚构班委','committee']] as [$no,$name,$role]) $accounts[]=['student_no'=>$no,'name'=>$name,'role'=>$role]+$auth->createUser($no,$name,$role);
        $u=['id'=>$accounts[0]['user_id'],'name'=>'虚构管理员','role'=>'admin','reauth_until'=>time()+300];
        $class=Support::one($this->db,'SELECT id,name FROM classes ORDER BY id LIMIT 1');
        Support::exec($this->db,'INSERT INTO class_permissions (user_id,class_id,created_at) VALUES (?,?,?)',[$accounts[1]['user_id'],$class['id'],$this->now()]);
        $csv="班级,学号,姓名,楼栋,房间号,寝室长学号\n";
        foreach ([['00990101','虚构负责人甲','501','00990101'],['00990102','虚构同学甲','501','00990101'],['00990103','虚构同学乙','501','00990101'],['00990201','虚构负责人乙','502','00990201'],['00990202','虚构同学丙','502','00990201']] as [$no,$name,$room,$leader]) $csv.=$class['name'].",$no,$name,示例楼,$room,$leader\n";
        $this->importCommit($u,['class_id'=>$class['id'],'csv'=>$csv,'hash'=>$this->previewHash($csv,$u['id'],(int)$class['id']),'confirm'=>true]);
        return ['warning'=>'以下均为虚构账号；激活码只显示此次。没有默认密码。','accounts'=>$accounts];
    }
}
