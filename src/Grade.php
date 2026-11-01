<?php
declare(strict_types=1);
namespace DormCheck;

use PDO;

/** Class identities remain stable while historical records retain their original names. */
final class Grade
{
    public static function preview(PDO $db, ?array $mapping = null): array
    {
        $version=(int)Schema::get($db,'schema_version',0);
        if ($version===2) return ['version'=>2,'ready'=>true,'already_migrated'=>true];
        if ($version!==1) throw new ApiError('不支持的数据库结构版本');
        $name=trim((string)Schema::get($db,'class_name',''));
        $names=[];
        if ($name!=='') $names[$name]=true;
        foreach (['students','placements','night_members'] as $table) {
            foreach (Support::all($db,"SELECT DISTINCT class_name FROM $table") as $row) $names[(string)$row['class_name']]=true;
        }
        $errors=[]; $lastId=0;
        do {
            $batch=Support::all($db,'SELECT id,snapshot_json FROM daily_reports WHERE id>? ORDER BY id LIMIT 10',[$lastId]);
            foreach ($batch as $row) {
                $lastId=(int)$row['id'];
                try { $snapshot=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR); }
                catch (\JsonException) { $errors[]='历史日报 '.$row['id'].' 无法解析，请先核实'; continue; }
                if (!is_array($snapshot) || !is_array($snapshot['people'] ?? null)) { $errors[]='历史日报 '.$row['id'].' 缺少人员明细，请先核实'; continue; }
                foreach ($snapshot['people'] as $person) {
                    if (!is_array($person) || !is_string($person['class_name'] ?? null)) { $errors[]='历史日报 '.$row['id'].' 有无法确定班级的人员'; continue; }
                    $names[$person['class_name']]=true;
                }
            }
        } while (count($batch)===10);
        if ($name==='') $errors[]='当前班级名称为空';
        $resolved=[];
        if ($mapping===null) {
            if (array_filter(array_keys($names),fn($value)=>(string)$value!==$name)) $errors[]='历史班级名称与当前设置不一致，请先核实并提供映射文件';
            elseif ($name!=='') $resolved[$name]=$name;
        } else {
            foreach ($names as $old=>$_) {
                if (!array_key_exists($old,$mapping) || !is_string($mapping[$old])) { $errors[]='映射文件缺少历史班级：'.(string)$old; continue; }
                $target=Support::text($mapping[$old],100);
                if ($target==='') { $errors[]='映射目标不能为空：'.(string)$old; continue; }
                $resolved[$old]=$target;
            }
            foreach ($mapping as $old=>$target) if (!array_key_exists($old,$names)) $errors[]='映射文件含未知历史班级：'.(string)$old;
            if (($resolved[$name] ?? null)!==$name) $errors[]='当前班级必须映射到其自身名称';
            foreach ($resolved as $old=>$target) if (isset($resolved[$target]) && $resolved[$target]!==$target) $errors[]='映射目标与其他历史班级名称冲突：'.$target;
        }
        $totp=Schema::get($db,'totp_student_no');
        $target=$totp?Support::one($db,'SELECT role FROM users WHERE student_no=?',[$totp]):null;
        if ($target && $target['role']==='leader') $errors[]='两步验证指定了旧宿舍长账号，请先更换指定账号';
        return ['version'=>1,'ready'=>!$errors,'original_class'=>$name,'historical_class_names'=>array_map('strval',array_keys($names)),'mapping'=>$resolved,'errors'=>array_values(array_unique($errors))];
    }

    private static function ensureTables(PDO $db): void
    {
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin':'';
        $db->exec("CREATE TABLE IF NOT EXISTS classes (id $id, name VARCHAR(100) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(19) NOT NULL)".$suffix);
        $db->exec("CREATE TABLE IF NOT EXISTS class_names (name VARCHAR(100) PRIMARY KEY, class_id BIGINT NOT NULL)".$suffix);
        $db->exec("CREATE TABLE IF NOT EXISTS class_permissions (user_id BIGINT NOT NULL, class_id BIGINT NOT NULL, created_at VARCHAR(19) NOT NULL, revoked_at VARCHAR(19), PRIMARY KEY(user_id,class_id))".$suffix);
    }

    public static function install(PDO $db, ?array $mapping = null): void
    {
        $preview=self::preview($db,$mapping);
        if (!empty($preview['already_migrated'])) return;
        if (!$preview['ready']) throw new ApiError('迁移预检未通过：'.implode('；',$preview['errors']));
        // CREATE is idempotent because MySQL may commit DDL independently.
        self::ensureTables($db);
        Schema::transaction($db,function () use ($db,$preview): void {
            $classIds=[];
            foreach (array_unique(array_values($preview['mapping'])) as $name) {
                $class=Support::one($db,'SELECT id FROM classes WHERE name=?',[$name]);
                if (!$class) {
                    Support::exec($db,'INSERT INTO classes (name,created_at) VALUES (?,?)',[$name,Support::now()]);
                    $class=['id'=>(int)$db->lastInsertId()];
                }
                $classIds[$name]=(int)$class['id'];
            }
            $aliases=$preview['mapping'];
            foreach ($classIds as $name=>$id) $aliases[$name]=$name;
            foreach ($aliases as $old=>$target) {
                $existing=Support::one($db,'SELECT class_id FROM class_names WHERE name=?',[(string)$old]);
                if ($existing && (int)$existing['class_id']!==$classIds[$target]) throw new ApiError('历史班级映射冲突，请检查迁移状态');
                if (!$existing) Support::exec($db,'INSERT INTO class_names (name,class_id) VALUES (?,?)',[(string)$old,$classIds[$target]]);
            }
            // Keep frozen snapshots and old placements intact; normalize only the current roster.
            $now=Support::now();
            foreach (Support::all($db,'SELECT * FROM students WHERE active=1 ORDER BY id') as $student) {
                $canonical=$preview['mapping'][$student['class_name']] ?? null;
                if ($canonical===null) throw new ApiError('当前名单存在未映射班级');
                if ($canonical===$student['class_name']) continue;
                Support::exec($db,'UPDATE students SET class_name=?,updated_at=? WHERE id=?',[$canonical,$now,$student['id']]);
                Support::exec($db,'UPDATE placements SET valid_to=? WHERE student_id=? AND valid_to IS NULL',[$now,$student['id']]);
                Support::exec($db,'INSERT INTO placements (student_id,room_id,student_no,name,class_name,leader_no,valid_from) VALUES (?,?,?,?,?,?,?)',[$student['id'],$student['room_id'],$student['student_no'],$student['name'],$canonical,$student['leader_no'],$now]);
            }
            $id=$classIds[$preview['original_class']];
            foreach (Support::all($db,"SELECT id FROM users WHERE role='committee'") as $u) {
                if (!Support::one($db,'SELECT user_id FROM class_permissions WHERE user_id=? AND class_id=?',[$u['id'],$id])) Support::exec($db,'INSERT INTO class_permissions (user_id,class_id,created_at) VALUES (?,?,?)',[$u['id'],$id,Support::now()]);
            }
            self::retireLegacyAccounts($db);
            Schema::set($db,'schema_version',2);
            Audit::write($db,null,'migration.grade',['original_class_id'=>$id,'mapped_names'=>count($preview['mapping'])]);
        });
    }

    private static function retireLegacyAccounts(PDO $db): void
    {
        Support::exec($db,"UPDATE auth_tokens SET used_at=? WHERE used_at IS NULL AND user_id IN (SELECT id FROM users WHERE role='leader')",[time()]);
        Support::exec($db,"UPDATE users SET role='retired',status='disabled',password_hash=NULL,totp_secret=NULL,totp_enabled=0,auth_version=auth_version+1,updated_at=? WHERE role='leader'",[Support::now()]);
        Support::exec($db,"UPDATE users SET auth_version=auth_version+1,updated_at=? WHERE role='committee'",[Support::now()]);
        Support::exec($db,'UPDATE grants SET revoked_at=? WHERE revoked_at IS NULL',[Support::now()]);
    }

    /** Null means all classes; an empty array means none. */
    public static function scope(PDO $db, array $user): ?array
    {
        if (($user['role'] ?? '')==='admin') return null;
        if (($user['role'] ?? '')!=='committee') throw new ApiError('没有此操作的权限','FORBIDDEN',403);
        return array_map(fn($r)=>(int)$r['class_id'],Support::all($db,'SELECT class_id FROM class_permissions WHERE user_id=? AND revoked_at IS NULL ORDER BY class_id',[$user['id']]));
    }

    public static function classId(PDO $db, string $name): ?int
    {
        $row=Support::one($db,'SELECT class_id FROM class_names WHERE name=?',[$name]);
        return $row?(int)$row['class_id']:null;
    }

    public static function includes(PDO $db, ?array $scope, string $class): bool
    {
        if ($scope===null) return true;
        $id=self::classId($db,$class);
        return $id!==null && in_array($id,$scope,true);
    }

    public static function requireClass(PDO $db, array $user, int $id): array
    {
        $class=Support::one($db,'SELECT * FROM classes WHERE id=? AND active=1',[$id]);
        $scope=self::scope($db,$user);
        if (!$class || ($scope!==null && !in_array($id,$scope,true))) throw new ApiError('班级不存在或不在授权范围','FORBIDDEN',403);
        return $class;
    }

    public static function classes(PDO $db, array $user): array
    {
        $scope=self::scope($db,$user);
        return array_values(array_filter(Support::all($db,'SELECT id,name,active FROM classes WHERE active=1 ORDER BY name'),fn($c)=>$scope===null || in_array((int)$c['id'],$scope,true)));
    }
}
