<?php
declare(strict_types=1);
namespace DormCheck;

use PDO;

final class Reports
{
    private const LABELS = ['present'=>'已在寝','away'=>'外出未归','exempt'=>'已核实无需返寝','unknown'=>'待核实','unreported'=>'未登记'];

    public static function build(PDO $db, string $date, string $now): array
    {
        $night=Support::one($db,'SELECT * FROM night_tasks WHERE business_date=?',[$date]);
        $rooms=[]; $people=[]; $events=[]; $followups=[]; $inspections=[];
        $metrics=['total'=>0,'present'=>0,'away'=>0,'exempt'=>0,'unknown'=>0,'unreported'=>0,'rooms_total'=>0,'rooms_submitted'=>0,'rooms_pending'=>0,'late_rooms'=>0];
        if ($night) {
            $rooms=Support::all($db,'SELECT * FROM room_tasks WHERE task_id=? ORDER BY building,room_number',[$night['id']]);
            $followups=Support::all($db,'SELECT * FROM followups WHERE task_id=? ORDER BY id',[$night['id']]);
            $inspections=Support::all($db,'SELECT * FROM inspections WHERE task_id=? ORDER BY id',[$night['id']]);
            $latest=[];
            foreach ($followups as $f) $latest[$f['student_id']]=$f;
            $exemptions=[];
            foreach (Support::all($db,'SELECT student_id,id,start_date,end_date,source,note,created_at FROM exemptions WHERE start_date<=? AND end_date>=? AND revoked_at IS NULL ORDER BY id DESC',[$date,$date]) as $e) if (!isset($exemptions[$e['student_id']])) { $id=$e['student_id']; unset($e['student_id']); $exemptions[$id]=$e; }
            $countsByRoom=[];
            $people=Support::all($db,'SELECT m.*,r.building,r.room_number,r.leader_no,r.leader_name,r.submitted_at,r.late,r.report_version,r.roster_version FROM night_members m JOIN room_tasks r ON r.task_id=m.task_id AND r.room_id=m.room_id WHERE m.task_id=? AND m.included=1 ORDER BY r.building,r.room_number,m.student_no',[$night['id']]);
            foreach ($people as &$p) {
                $p['task_id']=$p['task_id'].'.'.$p['room_id']; $p['student_id']=(int)$p['student_id']; $p['room_id']=(int)$p['room_id'];
                $p['room']=$p['building'].' · '.$p['room_number']; $p['status']=$p['current_status'];
                $p['frozen_exemption']=$p['exemption_json']?json_decode($p['exemption_json'],true,512,JSON_THROW_ON_ERROR):null;
                $p['exemption']=$exemptions[$p['student_id']] ?? null;
                $p['followup_state']=$latest[$p['student_id']]['state'] ?? 'pending'; $p['followup_note']=$latest[$p['student_id']]['note'] ?? '';
                $p['followup_actor']=$latest[$p['student_id']]['actor_name'] ?? '';
                $metrics['total']++;
                if (!array_key_exists($p['current_status'],self::LABELS)) throw new \RuntimeException('Unknown attendance projection');
                $metrics[$p['current_status']]++;
                $roomId=$p['room_id'];
                if (!isset($countsByRoom[$roomId])) $countsByRoom[$roomId]=['total'=>0,'present'=>0,'away'=>0,'exempt'=>0,'unknown'=>0,'unreported'=>0];
                $countsByRoom[$roomId]['total']++; $countsByRoom[$roomId][$p['current_status']]++;
                unset($p['exemption_json']);
            }
            unset($p);
            foreach ($rooms as &$r) {
                $r['id']=$r['task_id'].'.'.$r['room_id']; $r['room']=$r['building'].' · '.$r['room_number'];
                $r['room_id']=(int)$r['room_id']; $r['late']=(bool)$r['late'];
                $r['responsible']=json_decode($r['responsible_json'],true,512,JSON_THROW_ON_ERROR); unset($r['responsible_json']);
                $counts=['total'=>0,'present'=>0,'away'=>0,'exempt'=>0,'unknown'=>0,'unreported'=>0];
                $counts=$countsByRoom[$r['room_id']] ?? $counts;
                $r['metrics']=$counts; $r['roster_empty']=$counts['total']===0;
                if (!$r['roster_empty']) {
                    $metrics['rooms_total']++;
                    if ($r['submitted_at']) $metrics['rooms_submitted']++; else $metrics['rooms_pending']++;
                    if ($r['late']) $metrics['late_rooms']++;
                }
            }
            unset($r);
            $events=Support::all($db,'SELECT e.*,m.name AS student_name,m.student_no,r.building,r.room_number FROM attendance_events e LEFT JOIN night_members m ON m.task_id=e.task_id AND m.student_id=e.student_id JOIN room_tasks r ON r.task_id=e.task_id AND r.room_id=e.room_id WHERE e.task_id=? ORDER BY e.id',[$night['id']]);
            foreach ($events as &$e) {
                $e['room']=$e['building'].' · '.$e['room_number'];
                $e['before']=json_decode($e['before_json'],true,512,JSON_THROW_ON_ERROR); $e['after']=json_decode($e['after_json'],true,512,JSON_THROW_ON_ERROR);
                unset($e['before_json'],$e['after_json']);
            }
            unset($e);
        }
        $metrics['students_total']=$metrics['total']; $metrics['submitted_rooms']=$metrics['rooms_submitted']; $metrics['expected_rooms']=$metrics['rooms_total'];
        if ($metrics['total']===0) $arrival='尚无当晚名册，不能判断到齐';
        elseif ($metrics['away']+$metrics['unknown']+$metrics['unreported']>0) $arrival='仍有未归、待核实或未登记情况';
        elseif ($metrics['exempt']>0) $arrival='应归人员已到齐，另有 '.$metrics['exempt'].' 人获准不返寝';
        else $arrival='全员在寝';
        $summary="{$date} 晚查寝，截至 {$now}：名册 {$metrics['total']} 人，已在寝 {$metrics['present']} 人，外出未归 {$metrics['away']} 人，待核实 {$metrics['unknown']} 人，未登记 {$metrics['unreported']} 人，已核实无需返寝 {$metrics['exempt']} 人；寝室初报 {$metrics['rooms_submitted']}/{$metrics['rooms_total']}，其中迟报 {$metrics['late_rooms']} 间。{$arrival}。";
        return ['business_date'=>$date,'as_of'=>$now,'version'=>'latest','class_name'=>Schema::get($db,'grade_name','全年级'),'metrics'=>$metrics,'people'=>$people,'rooms'=>$rooms,'events'=>$events,'followups'=>$followups,'inspections'=>$inspections,'summary_text'=>$summary,'arrival_summary'=>$arrival,'frozen_at'=>$night['frozen_at'] ?? null,'check_at'=>$night['check_at'] ?? null,'due_at'=>$night['due_at'] ?? null,'delayed_freeze'=>(bool)($night['delayed'] ?? false),'phase'=>$night['phase'] ?? 'missing'];
    }

    /** Project historical snapshots through current class authorization, without mutating the snapshot. */
    public static function scoped(PDO $db, array $report, ?array $scope): array
    {
        if ($scope===null) return $report;
        $names=[];
        foreach (Support::all($db,'SELECT name,class_id FROM class_names') as $row) if (in_array((int)$row['class_id'],$scope,true)) $names[$row['name']]=true;
        $people=array_values(array_filter($report['people'] ?? [],fn($p)=>isset($names[(string)($p['class_name'] ?? '')])));
        $ids=array_map(fn($p)=>(int)$p['student_id'],$people);
        $events=array_values(array_filter($report['events'] ?? [],fn($e)=>isset($e['student_id']) && in_array((int)$e['student_id'],$ids,true)));
        $followups=array_values(array_filter($report['followups'] ?? [],fn($f)=>in_array((int)($f['student_id'] ?? 0),$ids,true)));
        $metrics=['total'=>0,'present'=>0,'away'=>0,'exempt'=>0,'unknown'=>0,'unreported'=>0,'rooms_total'=>0,'rooms_submitted'=>0,'rooms_pending'=>0,'late_rooms'=>0];
        $byRoom=[];
        foreach ($people as &$p) {
            $status=$p['current_status'];
            if (!array_key_exists($status,self::LABELS)) throw new \RuntimeException('Unknown attendance projection');
            $metrics['total']++; $metrics[$status]++; $byRoom[(int)$p['room_id']][]=$p;
            $initialEvents=array_values(array_filter($events,fn($e)=>(int)$e['student_id']===(int)$p['student_id'] && $e['type']==='initial'));
            $p['submitted_at']=$initialEvents ? end($initialEvents)['recorded_at'] : null;
            $p['late']=$p['submitted_at']!==null && !empty($report['due_at']) && $p['submitted_at']>$report['due_at'];
            $p['leader_no']=''; $p['leader_name']='';
        }
        unset($p);
        // Rebuild room groups after projecting member-level information.
        $byRoom=[]; foreach ($people as $p) $byRoom[(int)$p['room_id']][]=$p;
        $rooms=[];
        foreach ($report['rooms'] ?? [] as $r) {
            $members=$byRoom[(int)$r['room_id']] ?? [];
            if (!$members) continue;
            $counts=['total'=>count($members),'present'=>0,'away'=>0,'exempt'=>0,'unknown'=>0,'unreported'=>0];
            foreach ($members as $p) $counts[$p['current_status']]++;
            $done=!array_filter($members,fn($p)=>$p['initial_status']==='unreported' && empty($p['corrected_initial_status']));
            $times=array_values(array_filter(array_column($members,'submitted_at'))); sort($times);
            $r['submitted_at']=$done?($times ? end($times) : ($report['as_of'] ?? null)):null;
            $r['late']=(bool)array_filter($members,fn($p)=>$p['late']);
            $r['updated_at']=max(array_column($members,'updated_at'));
            $r['leader_no']=''; $r['leader_name']=''; $r['responsible']=[]; $r['metrics']=$counts; $r['roster_empty']=false;
            $metrics['rooms_total']++; $metrics[$done?'rooms_submitted':'rooms_pending']++; if ($r['late']) $metrics['late_rooms']++;
            $rooms[]=$r;
        }
        $metrics['students_total']=$metrics['total']; $metrics['submitted_rooms']=$metrics['rooms_submitted']; $metrics['expected_rooms']=$metrics['rooms_total'];
        $arrival=$metrics['total']===0?'尚无当晚名册，不能判断到齐':(($metrics['away']+$metrics['unknown']+$metrics['unreported'])>0?'仍有未归、待核实或未登记情况':($metrics['exempt']>0?'应归人员已到齐，另有 '.$metrics['exempt'].' 人获准不返寝':'全员在寝'));
        $date=$report['business_date']; $asOf=$report['as_of'];
        $summary="{$date} 晚查寝，截至 {$asOf}：名册 {$metrics['total']} 人，已在寝 {$metrics['present']} 人，外出未归 {$metrics['away']} 人，待核实 {$metrics['unknown']} 人，未登记 {$metrics['unreported']} 人，已核实无需返寝 {$metrics['exempt']} 人；涉及寝室初报 {$metrics['rooms_submitted']}/{$metrics['rooms_total']}。{$arrival}。";
        $classes=Support::all($db,'SELECT id,name FROM classes ORDER BY name');
        $labels=array_column(array_values(array_filter($classes,fn($c)=>in_array((int)$c['id'],$scope,true))),'name');
        $report['class_name']=implode('、',$labels); $report['people']=$people; $report['events']=$events; $report['followups']=$followups; $report['inspections']=[]; $report['rooms']=$rooms; $report['metrics']=$metrics; $report['summary_text']=$summary; $report['arrival_summary']=$arrival;
        unset($report['reason']);
        if (isset($report['versions'])) foreach ($report['versions'] as &$v) $v['label']='固定版本 '.$v['version'];
        unset($v);
        return $report;
    }

    public static function versions(PDO $db, string $date): array
    {
        return Support::all($db,'SELECT d.id,d.version,d.reason AS label,d.as_of,d.created_at FROM daily_reports d JOIN night_tasks n ON n.id=d.task_id WHERE n.business_date=? ORDER BY d.version DESC',[$date]);
    }

    public static function load(PDO $db, string $date, mixed $version, string $now): array
    {
        if ($version===null || $version==='' || $version==='latest') $report=self::build($db,$date,$now);
        else {
            if ((!is_int($version) && !is_string($version)) || !preg_match('/^[1-9][0-9]{0,14}$/D',(string)$version)) throw new ApiError('报表版本格式不正确');
            // The public selector uses the stable report id, never a file path.
            $row=Support::one($db,'SELECT d.* FROM daily_reports d JOIN night_tasks n ON n.id=d.task_id WHERE n.business_date=? AND d.id=?',[$date,$version]);
            if (!$row) throw new ApiError('报表版本不存在', 'NOT_FOUND',404);
            $report=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR); $report['snapshot_id']=(int)$row['id'];
        }
        $report['versions']=self::versions($db,$date);
        return $report;
    }

    public static function save(PDO $db, string $date, string $now, string $reason, ?string $autoKey = null): array
    {
        $n=Support::one($db,'SELECT id FROM night_tasks WHERE business_date=?',[$date]);
        if (!$n) throw new ApiError('该晚尚未建立任务，请先检查计划任务', 'TASK_MISSING',409);
        $row=Support::one($db,'SELECT MAX(version) AS v FROM daily_reports WHERE task_id=?',[$n['id']]);
        $report=self::build($db,$date,$now); $report['version']=(int)($row['v'] ?? 0)+1; $report['reason']=$reason;
        Support::exec($db,'INSERT INTO daily_reports (task_id,version,snapshot_json,reason,as_of,created_at,auto_key) VALUES (?,?,?,?,?,?,?)',[$n['id'],$report['version'],Support::json($report),$reason,$now,$now,$autoKey]);
        $report['snapshot_id']=(int)$db->lastInsertId(); $report['versions']=self::versions($db,$date);
        return $report;
    }

    private static function label(?string $status): string { return $status===null?'':(self::LABELS[$status] ?? $status); }

    public static function sheets(array $report): array
    {
        $m=$report['metrics']; $people=$report['people'];
        $summary=[['项目','内容'],['班级',(string)$report['class_name']],['查寝日期',(string)$report['business_date']],['数据截至',(string)$report['as_of']],['版本',(string)$report['version']],['生成说明',(string)($report['reason'] ?? '下载时最新快照')],['名册冻结时间',(string)($report['frozen_at'] ?? '尚未冻结')],['名册总人数',$m['total']],['已在寝',$m['present']],['外出未归',$m['away']],['已核实无需返寝',$m['exempt']],['待核实',$m['unknown']],['未登记',$m['unreported']],['应报寝室',$m['rooms_total']],['已初报寝室',$m['rooms_submitted']],['缺报寝室',$m['rooms_pending']],['迟报寝室',$m['late_rooms']],['到齐口径',(string)$report['arrival_summary']],['汇报文字',(string)$report['summary_text']],['说明','初报为查寝时点的申报；当前状态可能在之后变化。人工返寝时间与服务端接收时间分别保留；查寝不关联考核。']];
        $detail=[['学号','姓名','班级','当晚寝室','负责人学号','负责人姓名','原始初报','原始去向','更正后初报','更正后初报去向','当前状态','当前去向','初报接收时间','迟报','预计返寝时间','返寝/确认时间','最后更新时间','联系进度','联系说明','报告版本','名册版本']];
        $away=[['学号','姓名','当晚寝室','当前状态','去向','预计返寝时间','联系进度','跟进人','跟进说明','最后更新时间']];
        $missing=[['类型','学号','姓名','当晚寝室','负责人','当前已知情况','最后更新时间']];
        $exempt=[['学号','姓名','当晚寝室','当前状态','有效开始','有效结束','核实来源','简要说明']];
        foreach ($people as $p) {
            $detail[]=[(string)$p['student_no'],(string)$p['name'],(string)$p['class_name'],(string)$p['room'],(string)$p['leader_no'],(string)$p['leader_name'],self::label($p['initial_status']),(string)$p['initial_location'],self::label($p['corrected_initial_status']),(string)($p['corrected_initial_location'] ?? ''),self::label($p['current_status']),(string)$p['location'],(string)($p['submitted_at'] ?? ''),$p['late']?'是':'否',(string)($p['expected_return_at'] ?? ''),(string)($p['return_at'] ?? ''),(string)$p['updated_at'],(string)$p['followup_state'],(string)$p['followup_note'],(int)$p['report_version'],(int)$p['roster_version']];
            if ($p['current_status']==='away') $away[]=[(string)$p['student_no'],(string)$p['name'],(string)$p['room'],self::label($p['current_status']),(string)$p['location'],(string)($p['expected_return_at'] ?? ''),(string)$p['followup_state'],(string)$p['followup_actor'],(string)$p['followup_note'],(string)$p['updated_at']];
            if (in_array($p['current_status'],['unknown','unreported'],true)) $missing[]=[$p['current_status']==='unknown'?'人员待核实':'人员未登记',(string)$p['student_no'],(string)$p['name'],(string)$p['room'],(string)$p['leader_name'],(string)$p['location'],(string)$p['updated_at']];
            if ($p['exemption'] || $p['current_status']==='exempt') {
                $e=$p['exemption'] ?? [];
                $exempt[]=[(string)$p['student_no'],(string)$p['name'],(string)$p['room'],self::label($p['current_status']),(string)($e['start_date'] ?? ''),(string)($e['end_date'] ?? ''),(string)($e['source'] ?? ''),(string)($e['note'] ?? '')];
            }
        }
        foreach ($report['rooms'] as $r) if (!$r['submitted_at'] && !($r['roster_empty'] ?? false)) $missing[]=['寝室缺报','','',(string)$r['room'],(string)$r['leader_name'],'尚未收到整寝初报',(string)$r['updated_at']];
        $events=[['事件编号','类型','学号','姓名','当晚寝室','原状态','新状态/更正值','原内容','新内容','原因','操作者','人工申报发生时间','服务端接收时间']];
        foreach ($report['events'] as $e) $events[]=[(int)$e['id'],(string)$e['type'],(string)($e['student_no'] ?? ''),(string)($e['student_name'] ?? ''),(string)$e['room'],self::label($e['before_status']),self::label($e['after_status'] ?? $e['after']['corrected_initial_status'] ?? null),Support::json($e['before']),Support::json($e['after']),(string)$e['reason'],(string)$e['actor_name'],(string)($e['occurred_at'] ?? ''),(string)$e['recorded_at']];
        return [['name'=>'当日汇总','rows'=>$summary],['name'=>'全员明细','rows'=>$detail],['name'=>'应归未归','rows'=>$away],['name'=>'缺报与待核实','rows'=>$missing],['name'=>'请假与无需返寝','rows'=>$exempt],['name'=>'补报与更正记录','rows'=>$events]];
    }
}
