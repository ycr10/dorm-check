<?php
declare(strict_types=1);
namespace DormCheck;

use PDO;

final class Auth
{
    public function __construct(private PDO $db, private array $config) {}

    public function current(bool $activity = false): ?array
    {
        if (empty($_SESSION['uid'])) return null;
        $now = time();
        $u = Support::one($this->db, 'SELECT * FROM users WHERE id=?', [$_SESSION['uid']]);
        if (!$u || !in_array($u['role'], ['admin','committee'], true) || $u['status'] !== 'active' || (int)$u['auth_version'] !== (int)($_SESSION['auth_version'] ?? 0)
            || $now - (int)($_SESSION['issued_at'] ?? 0) >= $this->config['session_seconds']
            || $now - (int)($_SESSION['last_activity'] ?? 0) >= $this->config['idle_seconds']) {
            $this->clear(); return null;
        }
        if ($activity) $_SESSION['last_activity'] = $now;
        return $this->publicUser($u);
    }
    public function requireUser(bool $activity = false, bool $allowSetup = false): array
    {
        $u = $this->current($activity);
        if (!$u) throw new ApiError('登录已过期，请重新登录', 'UNAUTHENTICATED', 401);
        if (!$allowSetup && $u['totp_required'] && !$u['totp_enabled']) throw new ApiError('请先绑定该账号的验证器', 'MFA_SETUP_REQUIRED', 403);
        return $u;
    }
    public function requireRecent(array $u): void
    {
        if ((int)$u['reauth_until'] < time()) throw new ApiError('请重新验证身份后执行此操作', 'REAUTH_REQUIRED', 403);
    }
    public static function totpPolicy(PDO $db, array $u): array
    {
        $studentNo = Schema::get($db, 'totp_student_no');
        return [
            'totp_required' => $studentNo === null ? true : $u['student_no'] === $studentNo,
            'totp_allowed' => $studentNo === null || $u['student_no'] === $studentNo,
        ];
    }
    public function publicUser(array $u): array
    {
        $role = $u['role'];
        $policy = self::totpPolicy($this->db, $u);
        return [
            'id' => (int)$u['id'], 'student_no' => $u['student_no'], 'name' => $u['name'], 'role' => $role,
            'roles' => $role === 'admin' ? ['admin', 'committee'] : [$role],
            'totp_enabled' => (bool)$u['totp_enabled'], 'totp_required' => $policy['totp_required'], 'totp_allowed' => $policy['totp_allowed'],
            'reauth_until' => (int)($_SESSION['reauth_until'] ?? 0),
        ];
    }
    private function clear(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    private function begin(array $u, bool $recent): array
    {
        $this->clear(); $now = time();
        $_SESSION += ['uid' => (int)$u['id'], 'auth_version' => (int)$u['auth_version'], 'issued_at' => $now, 'last_activity' => $now,
            'reauth_until' => $recent ? $now + (int)$this->config['reauth_seconds'] : 0];
        return $this->publicUser($u);
    }
    private function bucket(string $label): string { return hash('sha256', $label); }
    private function checkRate(string $label, int $limit = 5, int $window = 900): void
    {
        $r = Support::one($this->db, 'SELECT * FROM rate_limits WHERE bucket=?', [$this->bucket($label)]);
        if ($r && (int)$r['blocked_until'] > time()) throw new ApiError('尝试次数较多，请稍后再试', 'RATE_LIMITED', 429);
    }
    private function fail(string $label, int $limit = 5, int $window = 900): void
    {
        $key = $this->bucket($label); $now = time();
        $r = Support::one($this->db, 'SELECT * FROM rate_limits WHERE bucket=?', [$key]);
        if (!$r) {
            try { Support::exec($this->db, 'INSERT INTO rate_limits (bucket,window_start,attempts,blocked_until) VALUES (?,?,?,?)', [$key,$now,1,0]); }
            catch (\PDOException) { Support::exec($this->db, 'UPDATE rate_limits SET attempts=attempts+1 WHERE bucket=?', [$key]); }
            return;
        }
        if ($now - (int)$r['window_start'] > $window) Support::exec($this->db, 'UPDATE rate_limits SET window_start=?,attempts=1,blocked_until=0 WHERE bucket=?', [$now,$key]);
        else {
            Support::exec($this->db, 'UPDATE rate_limits SET attempts=attempts+1 WHERE bucket=?', [$key]);
            $current=Support::one($this->db,'SELECT attempts FROM rate_limits WHERE bucket=?',[$key]);
            $level=min(4,intdiv(max(0,(int)$current['attempts']-$limit),$limit));
            $cooldown=min($window,60*(2**$level));
            Support::exec($this->db, 'UPDATE rate_limits SET blocked_until=? WHERE bucket=? AND attempts>=?', [$now+$cooldown,$key,$limit]);
        }
    }
    private function successful(string $label): void { Support::exec($this->db, 'DELETE FROM rate_limits WHERE bucket=?', [$this->bucket($label)]); }
    private function tokenHash(string $raw): string { return hash_hmac('sha256', $raw, base64_decode($this->config['app_key'], true)); }
    private function verifyFactor(array $u, string $otp): bool
    {
        if (!$u['totp_enabled']) return !self::totpPolicy($this->db, $u)['totp_required'];
        $secret = Security::decrypt($u['totp_secret'], $this->config);
        $step = Security::totpStep($secret, $otp, time(), (int)$u['totp_last_step']);
        if ($step !== null) return Support::exec($this->db, 'UPDATE users SET totp_last_step=? WHERE id=? AND totp_last_step<?', [$step,$u['id'],$step]) === 1;
        return false;
    }
    private function recoveryFactor(array $u, string $code): bool
    {
        if (!preg_match('/^[A-Fa-f0-9]{8}-[A-Fa-f0-9]{8}$/D', $code)) return false;
        $h = $this->tokenHash(strtolower($code));
        return Schema::transaction($this->db, function () use ($u, $h): bool {
            $current = Support::one($this->db, 'SELECT * FROM users WHERE id=?', [$u['id']]);
            if (!$current || $current['status'] !== 'active' || !$current['totp_enabled'] || (int)$current['auth_version'] !== (int)$u['auth_version'] || !self::totpPolicy($this->db, $current)['totp_allowed']) return false;
            $n = Support::exec($this->db, 'UPDATE recovery_codes SET used_at=? WHERE user_id=? AND code_hash=? AND used_at IS NULL', [time(),$u['id'],$h]);
            if ($n !== 1) return false;
            Support::exec($this->db, 'UPDATE users SET totp_secret=NULL,totp_enabled=0,totp_last_step=-1,auth_version=auth_version+1,updated_at=? WHERE id=?', [Support::now(),$u['id']]);
            Support::exec($this->db, 'UPDATE recovery_codes SET used_at=? WHERE user_id=? AND used_at IS NULL', [time(),$u['id']]);
            Audit::write($this->db,(int)$u['id'],'auth.mfa_recovery',[]); return true;
        });
    }
    public function handle(string $action, array $data): array
    {
        if ($action === 'session') return ['user' => $this->current(), 'server_time' => Support::now(), 'config' => ['class_name'=>Schema::get($this->db,'grade_name','全年级'),'check_time'=>Schema::get($this->db,'check_time','22:30'),'timezone'=>'Asia/Shanghai','environment'=>$this->config['env']]];
        if ($action === 'login') {
            $no = Support::text($data['student_no'] ?? '',64); $password = (string)($data['password'] ?? '');
            if (strlen($password) > 512) throw new ApiError('学号或密码不正确','LOGIN_FAILED',401);
            $label = 'login:'.$no; $ipLabel = 'login-ip:'.($_SERVER['REMOTE_ADDR'] ?? 'cli');
            $this->checkRate($label); $this->checkRate($ipLabel,100,900);
            $u = Support::one($this->db,'SELECT * FROM users WHERE student_no=?',[$no]);
            // Run a real password verifier even for unknown IDs, reducing account-enumeration timing differences.
            $dummy = '$argon2id$v=19$m=19456,t=2,p=1$Tm9uZXhpc3RlbnRVc2VyMQ$g9VDc4E4J2wmwGh1Mtn3k2WGRJGi3vA11fGPGzTZV64';
            $valid = password_verify($password, $u['password_hash'] ?? $dummy);
            if (!$u || !in_array($u['role'],['admin','committee'],true) || $u['status'] !== 'active' || !$valid) {
                $this->fail($label); $this->fail($ipLabel,100,900);
                Audit::write($this->db,null,'auth.login_failed',['account_tag'=>substr(hash('sha256',$no),0,16)]);
                throw new ApiError('学号或密码不正确','LOGIN_FAILED',401);
            }
            if ($u['totp_enabled']) {
                $otp = trim((string)($data['otp'] ?? ''));
                if ($otp === '') return ['needs_totp'=>true];
                if (!$this->verifyFactor($u,$otp)) {
                    if ($this->recoveryFactor($u,$otp)) {
                        $u = Support::one($this->db,'SELECT * FROM users WHERE id=?',[$u['id']]);
                        $this->successful($label); return ['user'=>$this->begin($u,false),'must_reenroll'=>true];
                    }
                    $this->fail($label); throw new ApiError('动态码无效、已使用或已过期','OTP_INVALID',401);
                }
            }
            $this->successful($label); Audit::write($this->db,(int)$u['id'],'auth.login',[]);
            return ['user'=>$this->begin($u,(bool)$u['totp_enabled'] || !self::totpPolicy($this->db, $u)['totp_required'])];
        }
        if (in_array($action,['activate','reset_password'],true)) {
            $no=Support::text($data['student_no']??'',64); $code=trim((string)($data['code']??''));
            $label='token:'.$no; $this->checkRate($label);
            $u=Support::one($this->db,'SELECT * FROM users WHERE student_no=?',[$no]);
            $type=$action==='activate'?'activate':'reset';
            $token=$u?Support::one($this->db,'SELECT * FROM auth_tokens WHERE user_id=? AND token_hash=? AND token_type=? AND used_at IS NULL AND expires_at>?',[$u['id'],$this->tokenHash($code),$type,time()]):null;
            if (!$u || !$token || !in_array($u['role'],['admin','committee'],true) || $u['status']==='disabled') { $this->fail($label); throw new ApiError('学号或一次性凭据无效、已使用或已过期','TOKEN_INVALID',422); }
            $hash=Security::password((string)($data['password']??''),$no);
            $this->db->beginTransaction();
            try {
                if (Support::exec($this->db,'UPDATE auth_tokens SET used_at=? WHERE id=? AND used_at IS NULL AND expires_at>?',[time(),$token['id'],time()])!==1) throw new ApiError('凭据已使用','TOKEN_INVALID',409);
                Support::exec($this->db,"UPDATE users SET password_hash=?,status='active',auth_version=auth_version+1,updated_at=? WHERE id=?",[$hash,Support::now(),$u['id']]);
                Support::exec($this->db,'UPDATE auth_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL',[time(),$u['id']]);
                Audit::write($this->db,(int)$u['id'],'auth.'.$action,[]); $this->db->commit();
            } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
            $this->successful($label); $this->clear(); return ['message'=>'密码已设置，请使用学号登录'];
        }
        $u=$this->requireUser(true,true); $raw=Support::one($this->db,'SELECT * FROM users WHERE id=?',[$u['id']]);
        if ($action==='logout') { Audit::write($this->db,$u['id'],'auth.logout'); $this->clear(); return ['message'=>'已退出']; }
        if ($action==='logout_all') {
            Support::exec($this->db,'UPDATE users SET auth_version=auth_version+1 WHERE id=?',[$u['id']]);
            Audit::write($this->db,$u['id'],'auth.logout_all'); $this->clear(); return ['message'=>'已退出所有设备'];
        }
        if ($action==='reauth') {
            $label='reauth:'.$u['id']; $this->checkRate($label);
            if (!password_verify((string)($data['password']??''),$raw['password_hash']) || ($raw['totp_enabled'] && !$this->verifyFactor($raw,trim((string)($data['otp']??''))))) {
                $this->fail($label); throw new ApiError('身份验证未通过，请检查密码和新的动态码','REAUTH_FAILED',403);
            }
            if ($u['totp_required'] && !$u['totp_enabled']) throw new ApiError('请先绑定验证器','MFA_SETUP_REQUIRED',403);
            $this->successful($label); $_SESSION['reauth_until']=time()+$this->config['reauth_seconds'];
            return ['reauth_until'=>$_SESSION['reauth_until'],'user'=>$this->publicUser($raw)];
        }
        if ($action==='totp_setup') {
            if (!self::totpPolicy($this->db, $raw)['totp_allowed']) throw new ApiError('仅系统设置指定的学号可以绑定两步验证', 'MFA_NOT_ALLOWED', 403);
            if ($raw['totp_enabled']) throw new ApiError('验证器已绑定，请使用受控恢复流程更换','MFA_ALREADY_ENABLED',409);
            if (!isset($_SESSION['pending_totp'])) $_SESSION['pending_totp']=Security::base32(random_bytes(20));
            $secret=$_SESSION['pending_totp'];
            return ['secret'=>$secret,'otpauth_url'=>'otpauth://totp/'.rawurlencode('归寝:'.$u['student_no']).'?secret='.$secret.'&issuer='.rawurlencode('归寝').'&algorithm=SHA1&digits=6&period=30'];
        }
        if ($action==='totp_confirm') {
            $label='totp-confirm:'.$u['id']; $this->checkRate($label);
            try {
                $codes=Schema::transaction($this->db, function () use ($u, $data): array {
                    $current=Support::one($this->db,'SELECT * FROM users WHERE id=?',[$u['id']]);
                    if (!$current || $current['status']!=='active' || (int)$current['auth_version']!==(int)($_SESSION['auth_version'] ?? 0)) throw new ApiError('登录已过期，请重新登录','UNAUTHENTICATED',401);
                    if (!self::totpPolicy($this->db,$current)['totp_allowed']) throw new ApiError('仅系统设置指定的学号可以绑定两步验证','MFA_NOT_ALLOWED',403);
                    if ($current['totp_enabled']) throw new ApiError('验证器已绑定','MFA_ALREADY_ENABLED',409);
                    $secret=$_SESSION['pending_totp']??'';
                    $step=$secret!==''?Security::totpStep($secret,trim((string)($data['code']??'')),time()):null;
                    if ($step===null) throw new ApiError('动态码无效，请确认验证器时间','OTP_INVALID',422);
                    Support::exec($this->db,'UPDATE users SET totp_secret=?,totp_enabled=1,totp_last_step=?,auth_version=auth_version+1,updated_at=? WHERE id=?',[Security::encrypt($secret,$this->config),$step,Support::now(),$u['id']]);
                    Support::exec($this->db,'DELETE FROM recovery_codes WHERE user_id=?',[$u['id']]);
                    $codes=[];
                    for($i=0;$i<8;$i++) { $code=bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(4)); $codes[]=$code; Support::exec($this->db,'INSERT INTO recovery_codes (user_id,code_hash) VALUES (?,?)',[$u['id'],$this->tokenHash($code)]); }
                    Audit::write($this->db,$u['id'],'auth.mfa_enabled'); return $codes;
                });
            } catch (ApiError $e) { if ($e->errorCode==='OTP_INVALID') $this->fail($label); throw $e; }
            $raw=Support::one($this->db,'SELECT * FROM users WHERE id=?',[$u['id']]); $this->begin($raw,true);
            return ['recovery_codes'=>$codes,'user'=>$this->publicUser($raw)];
        }
        if($action==='change_password') {
            $label='change-password:'.$u['id']; $this->checkRate($label);
            if(!password_verify((string)($data['current_password']??''),$raw['password_hash'])) { $this->fail($label); throw new ApiError('当前密码不正确','PASSWORD_INVALID',403); }
            if($u['totp_enabled'])$this->requireRecent($u);
            $hash=Security::password((string)($data['password']??''),$u['student_no']);
            Support::exec($this->db,'UPDATE users SET password_hash=?,auth_version=auth_version+1,updated_at=? WHERE id=?',[$hash,Support::now(),$u['id']]);
            Support::exec($this->db,'UPDATE auth_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL',[time(),$u['id']]);
            Audit::write($this->db,$u['id'],'auth.password_changed'); $this->clear(); return ['message'=>'密码已修改，请重新登录'];
        }
        throw new ApiError('接口不存在','NOT_FOUND',404);
    }
    public function createUser(string $studentNo,string $name,string $role,?int $actor=null): array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{3,64}$/D',$studentNo)||$name===''||!in_array($role,['committee','admin'],true))throw new ApiError('请填写正确的学号、姓名和角色');
        $existing=Support::one($this->db,'SELECT id,role FROM users WHERE student_no=?',[$studentNo]);
        if($existing && $existing['role']!=='retired')throw new ApiError('此学号已经建立账号','DUPLICATE_ACCOUNT',409);
        if($existing) {
            $id=(int)$existing['id'];
            Support::exec($this->db,"UPDATE users SET name=?,role=?,status='pending',updated_at=? WHERE id=?",[Support::text($name,100),$role,Support::now(),$id]);
        } else {
            Support::exec($this->db,'INSERT INTO users (student_no,name,role,status,created_at,updated_at) VALUES (?,?,?,?,?,?)',[$studentNo,Support::text($name,100),$role,'pending',Support::now(),Support::now()]);
            $id=(int)$this->db->lastInsertId();
        }
        $token=$this->issueToken($id,'activate',$actor);
        Audit::write($this->db,$actor,'users.create',['user_id'=>$id,'role'=>$role]);
        return ['user_id'=>$id,'activation_code'=>$token['code'],'expires_at'=>$token['expires_at']];
    }
    public function issueToken(int $userId,string $type,?int $actor=null): array
    {
        $u=Support::one($this->db,'SELECT id,status,role FROM users WHERE id=?',[$userId]);
        if(!$u||$u['status']==='disabled'||!in_array($u['role'],['admin','committee'],true))throw new ApiError('账号不存在或已停用');
        $code=bin2hex(random_bytes(16)); $expires=time()+($type==='activate'?86400:1800);
        $this->db->beginTransaction();
        try {
            Support::exec($this->db,'UPDATE auth_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL',[time(),$userId]);
            Support::exec($this->db,'INSERT INTO auth_tokens (user_id,token_hash,token_type,expires_at,created_at) VALUES (?,?,?,?,?)',[$userId,$this->tokenHash($code),$type,$expires,time()]);
            Support::exec($this->db,'UPDATE users SET auth_version=auth_version+1 WHERE id=?',[$userId]);
            Audit::write($this->db,$actor,'users.token_issued',['user_id'=>$userId,'type'=>$type]); $this->db->commit();
        } catch(\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack();throw $e; }
        return ['code'=>$code,'expires_at'=>date('c',$expires)];
    }
    public function manage(string $action,array $u,array $data): array
    {
        if($u['role']!=='admin')throw new ApiError('此操作需要系统管理权限','FORBIDDEN',403);
        $this->requireRecent($u);
        if($action==='users.list')return ['users'=>Support::all($this->db,"SELECT id,student_no,name,role,status,totp_enabled,created_at FROM users WHERE role IN ('admin','committee') ORDER BY id")];
        if($action==='user_create')return $this->createUser(Support::text($data['student_no']??'',64),Support::text($data['name']??'',100),Support::text($data['role']??'committee',20),$u['id']);
        $id=(int)($data['user_id']??0);
        if($action==='user_reset') { $t=$this->issueToken($id,'reset',$u['id']); return ['reset_code'=>$t['code'],'expires_at'=>$t['expires_at']]; }
        if($action==='user_status') {
            if($id===$u['id'])throw new ApiError('不能通过此入口停用自己');
            $status=$data['status']??''; if(!in_array($status,['disabled','active'],true))throw new ApiError('状态无效');
            $target=Support::one($this->db,'SELECT * FROM users WHERE id=?',[$id]);
            if(!$target || !in_array($target['role'],['admin','committee'],true))throw new ApiError('账号不存在','NOT_FOUND',404);
            if($status==='active'&&!$target['password_hash'])throw new ApiError('请先发放激活或恢复凭据');
            Support::exec($this->db,'UPDATE users SET status=?,auth_version=auth_version+1,updated_at=? WHERE id=?',[$status,Support::now(),$id]);
            Support::exec($this->db,'UPDATE auth_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL',[time(),$id]);
            Audit::write($this->db,$u['id'],'users.status',['user_id'=>$id,'status'=>$status]);return ['message'=>'账号状态已更新'];
        }
        throw new ApiError('接口不存在','NOT_FOUND',404);
    }
}
