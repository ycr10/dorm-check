<?php
declare(strict_types=1);
namespace DormCheck;

use PDO;

final class Backup
{
    private const MAGIC = "DORMBACKUP2\n";
    private const CHUNK = 1048576;
    private const LIMIT = 536870912;
    private const TABLES = ['users','auth_tokens','recovery_codes','rate_limits','audit_logs','app_lock','settings','rooms','students','placements','grants','exemptions','night_tasks','room_tasks','night_members','attendance_events','request_keys','followups','inspections','daily_reports','drafts','job_runs'];

    public function __construct(private PDO $db, private array $config) {}

    public static function maintenance(array $config, bool $blockAll = false, bool $recovered = false): void
    {
        $file=$config['storage_path'].'/maintenance.json';
        $tmp=$file.'.'.bin2hex(random_bytes(6));
        if (file_put_contents($tmp,Support::json(['since'=>Support::now(),'block_all'=>$blockAll,'recovered'=>$recovered]),LOCK_EX)===false) throw new \RuntimeException('Cannot enable maintenance mode');
        chmod($tmp,0600);
        if (!rename($tmp,$file)) { unlink($tmp); throw new \RuntimeException('Cannot publish maintenance marker'); }
    }

    private function key(): string
    {
        $key=base64_decode(getenv('DORM_BACKUP_KEY') ?: '',true);
        if ($key===false || strlen($key)!==32) throw new ApiError('请通过私有环境变量 DORM_BACKUP_KEY 提供 32 字节随机密钥的 base64');
        return $key;
    }

    private function directory(): string
    {
        $path=$this->config['storage_path'].'/maintenance/tmp-'.bin2hex(random_bytes(12));
        if (!mkdir($path,0700,true)) throw new \RuntimeException('Cannot create private maintenance directory');
        return $path;
    }

    private function clean(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) if (is_file($file) && !is_link($file)) unlink($file);
        rmdir($dir);
    }

    private function mysqlOptions(string $dir): array
    {
        $dsn=$this->config['db_dsn']; $options=[];
        foreach (explode(';',substr($dsn,6)) as $part) {
            if (!str_contains($part,'=')) continue; [$key,$value]=explode('=',$part,2); $options[$key]=$value;
        }
        if (empty($options['dbname']) || !preg_match('/^[A-Za-z0-9_]+$/D',$options['dbname'])) throw new ApiError('备份仅支持标准 MySQL dbname');
        $quote=static fn(string $v): string=>'"'.str_replace(["\\","\"","\n","\r"],["\\\\","\\\"","\\n","\\r"],$v).'"';
        $content="[client]\nuser=".$quote($this->config['db_user'] ?? '')."\npassword=".$quote($this->config['db_password'] ?? '')."\n";
        foreach (['host','port','unix_socket'] as $key) if (isset($options[$key])) $content.=($key==='unix_socket'?'socket':$key).'='.$quote($options[$key])."\n";
        $file=$dir.'/mysql.cnf';
        if (file_put_contents($file,$content,LOCK_EX)===false) throw new \RuntimeException('Cannot write private MySQL options');
        chmod($file,0600);
        return [$file,$options['dbname']];
    }

    private function process(array $command, ?string $input, string $dir): void
    {
        $error=$dir.'/process-error.log';
        $descriptors=[0=>['file',$input ?? (PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null'),'r'],1=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','w'],2=>['file',$error,'w']];
        $process=proc_open($command,$descriptors,$pipes,null,null,['bypass_shell'=>true]);
        if (!is_resource($process)) throw new \RuntimeException('Cannot start database maintenance utility');
        $exit=proc_close($process);
        if ($exit!==0) throw new \RuntimeException('Database maintenance utility failed (exit '.$exit.'); check utility installation, credentials and database privileges');
    }

    private function encrypt(string $source, string $output, string $key): void
    {
        $in=fopen($source,'rb'); $out=fopen($output,'xb');
        if (!$in || !$out) { if (is_resource($in)) fclose($in); throw new ApiError('无法创建备份；输出文件不能已经存在'); }
        chmod($output,0600); $ok=false;
        try {
            $id=random_bytes(16); self::put($out,self::MAGIC.$id); $index=0;
            while (true) {
                $plain=fread($in,self::CHUNK);
                if ($plain===false) throw new \RuntimeException('Cannot read backup archive');
                $iv=random_bytes(12); $tag='';
                $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'DormCheckBackup:v2:'.bin2hex($id).':'.$index);
                if ($cipher===false) throw new \RuntimeException('Backup encryption failed');
                self::put($out,pack('N',strlen($cipher)).$iv.$tag.$cipher); $index++;
                if ($plain==='') break;
            }
            $ok=true;
        } finally { fclose($in); fclose($out); if (!$ok && is_file($output)) unlink($output); }
    }

    private static function put($stream, string $value): void
    {
        $offset=0; $length=strlen($value);
        while ($offset<$length) { $n=fwrite($stream,substr($value,$offset)); if ($n===false || $n===0) throw new \RuntimeException('Backup write failed'); $offset+=$n; }
    }

    private static function take($stream, int $length): string
    {
        $out='';
        while (strlen($out)<$length) { $part=fread($stream,$length-strlen($out)); if ($part===false || $part==='') throw new ApiError('备份文件截断或格式无效'); $out.=$part; }
        return $out;
    }

    private function decrypt(string $source, string $output, string $key): void
    {
        $in=fopen($source,'rb'); if (!$in) throw new ApiError('无法读取备份文件');
        $out=fopen($output,'xb'); if (!$out) { fclose($in); throw new \RuntimeException('Cannot create private restore archive'); }
        chmod($output,0600);
        try {
            if (self::take($in,strlen(self::MAGIC))!==self::MAGIC) throw new ApiError('不支持的备份格式');
            $id=self::take($in,16); $index=0; $total=0;
            while (true) {
                $length=unpack('N',self::take($in,4))[1];
                if ($length>self::CHUNK || $total+$length>self::LIMIT) throw new ApiError('备份超过恢复大小限制');
                $iv=self::take($in,12); $tag=self::take($in,16); $cipher=$length>0?self::take($in,$length):'';
                $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'DormCheckBackup:v2:'.bin2hex($id).':'.$index);
                if ($plain===false) throw new ApiError('备份校验失败：密钥错误或文件已损坏');
                if ($length===0) { if (fread($in,1)!=='') throw new ApiError('备份含异常尾随数据'); break; }
                self::put($out,$plain); $total+=$length; $index++;
            }
        } finally { fclose($in); fclose($out); }
    }

    public function create(string $output): array
    {
        $key=$this->key();
        if (!is_dir(dirname($output)) || file_exists($output)) throw new ApiError('输出目录必须存在，且不能覆盖已有备份');
        $dir=$this->directory();
        try {
            $driver=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME); $database=$dir.'/database.'.($driver==='sqlite'?'sqlite':'sql');
            if ($driver==='sqlite') $this->db->exec('VACUUM INTO '.$this->db->quote($database));
            else {
                [$options,$name]=$this->mysqlOptions($dir);
                $this->process(['mysqldump','--defaults-extra-file='.$options,'--single-transaction','--no-tablespaces','--skip-comments','--default-character-set=utf8mb4','--result-file='.$database,$name],null,$dir);
            }
            if (!is_file($database) || filesize($database)>self::LIMIT-1048576) throw new ApiError('数据库快照无效或超过 512 MB 备份限制');
            $manifest=['format'=>2,'driver'=>$driver,'schema_version'=>Schema::get($this->db,'schema_version'),'created_at'=>Support::now(),'class_name'=>Schema::get($this->db,'class_name'),'database_sha256'=>hash_file('sha256',$database),'application_config'=>array_intersect_key($this->config,array_flip(['app_key','app_url','class_name','timezone','check_time','due_time','idle_seconds','session_seconds','reauth_seconds']))];
            $zip=new \ZipArchive();
            if ($zip->open($dir.'/bundle.zip',\ZipArchive::CREATE|\ZipArchive::EXCL)!==true) throw new \RuntimeException('Cannot create backup archive');
            if (!$zip->addFile($database,basename($database)) || !$zip->addFromString('manifest.json',Support::json($manifest)) || !$zip->close()) throw new \RuntimeException('Cannot finish backup archive');
            $this->encrypt($dir.'/bundle.zip',$output,$key);
            Audit::write($this->db,null,'maintenance.backup',['created_at'=>$manifest['created_at'],'driver'=>$driver]);
            return ['file'=>$output,'created_at'=>$manifest['created_at'],'sha256'=>hash_file('sha256',$output),'bytes'=>filesize($output)];
        } finally { $this->clean($dir); }
    }

    public function restore(string $input): array
    {
        $key=$this->key(); $marker=$this->config['storage_path'].'/maintenance.json';
        if (!is_file($marker)) throw new ApiError('请先启用维护模式，并在独立数据库完成恢复演练');
        if (!is_file($input) || is_link($input) || filesize($input)>self::LIMIT+1048576) throw new ApiError('备份文件无效或过大');
        $dir=$this->directory();
        try {
            $this->decrypt($input,$dir.'/bundle.zip',$key);
            $zip=new \ZipArchive();
            if ($zip->open($dir.'/bundle.zip')!==true) throw new ApiError('备份内部归档无效');
            try {
                $stat=$zip->statName('manifest.json');
                if (!$stat || $stat['size']>65536) throw new ApiError('备份说明无效');
                $manifest=json_decode($zip->getFromName('manifest.json'),true,64,JSON_THROW_ON_ERROR);
                $driver=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
                if (($manifest['format'] ?? 0)!==2 || ($manifest['schema_version'] ?? null)!==1 || ($manifest['driver'] ?? '')!==$driver) throw new ApiError('备份格式、数据库类型或结构版本不兼容');
                $name='database.'.($driver==='sqlite'?'sqlite':'sql'); $stat=$zip->statName($name);
                if (!$stat || $stat['size']>self::LIMIT) throw new ApiError('备份数据库条目无效或过大');
                $stream=$zip->getStream($name); $target=fopen($dir.'/'.$name,'xb');
                if (!$stream || !$target) throw new \RuntimeException('Cannot open private restore payload');
                try { $bytes=stream_copy_to_stream($stream,$target,self::LIMIT+1); if ($bytes===false || $bytes>self::LIMIT || $bytes!==$stat['size']) throw new ApiError('备份数据库长度无效'); }
                finally { fclose($stream); fclose($target); }
                if (!hash_equals((string)$manifest['database_sha256'],hash_file('sha256',$dir.'/'.$name))) throw new ApiError('备份数据库校验失败');
            } finally { $zip->close(); }
            self::maintenance($this->config,true);
            if ($driver==='sqlite') $this->restoreSqlite($dir.'/'.$name,$manifest);
            else {
                [$options,$database]=$this->mysqlOptions($dir);
                $this->process(['mysql','--defaults-extra-file='.$options,'--default-character-set=utf8mb4',$database],$dir.'/'.$name,$dir);
                Schema::transaction($this->db,fn()=> $this->invalidate($manifest));
            }
            $sessions=$this->config['storage_path'].'/sessions';
            foreach (glob($sessions.'/sess_*') ?: [] as $session) if (is_file($session) && !is_link($session)) unlink($session);
            self::maintenance($this->config,false,true);
            return ['restored_at'=>Support::now(),'backup_created_at'=>$manifest['created_at'],'maintenance'=>true,'message'=>'旧密码、会话、一次性码、二次验证及旧授权均已撤销。核实名单后重新发放重置码、绑定验证器、明确重建授权。配置未自动覆盖。'];
        } finally { $this->clean($dir); }
    }

    private function restoreSqlite(string $source, array $manifest): void
    {
        $this->db->exec('ATTACH DATABASE '.$this->db->quote($source).' AS restored');
        try {
            $check=$this->db->query('PRAGMA restored.integrity_check')->fetchColumn();
            if ($check!=='ok') throw new ApiError('备份数据库完整性检查失败');
            foreach (self::TABLES as $table) {
                $live=$this->db->query('PRAGMA main.table_info('.$table.')')->fetchAll();
                $saved=$this->db->query('PRAGMA restored.table_info('.$table.')')->fetchAll();
                if (array_column($live,'name')!==array_column($saved,'name') || !$live) throw new ApiError('备份数据库结构与当前版本不符');
            }
            Schema::transaction($this->db,function () use ($manifest): void {
                foreach (array_reverse(self::TABLES) as $table) $this->db->exec('DELETE FROM main.'.$table);
                foreach (self::TABLES as $table) $this->db->exec('INSERT INTO main.'.$table.' SELECT * FROM restored.'.$table);
                $this->invalidate($manifest);
            });
        } finally { $this->db->exec('DETACH DATABASE restored'); }
    }

    private function invalidate(array $manifest): void
    {
        Support::exec($this->db,"UPDATE users SET status=CASE WHEN status='disabled' THEN 'disabled' ELSE 'pending' END,password_hash=NULL,auth_version=auth_version+1,totp_secret=NULL,totp_enabled=0,totp_last_step=-1,updated_at=?",[Support::now()]);
        foreach (['auth_tokens','recovery_codes','rate_limits','request_keys','drafts'] as $table) $this->db->exec('DELETE FROM '.$table);
        Support::exec($this->db,'UPDATE grants SET revoked_at=? WHERE revoked_at IS NULL',[Support::now()]);
        Audit::write($this->db,null,'maintenance.restore',['backup_created_at'=>$manifest['created_at'],'restored_at'=>Support::now(),'credentials_revoked'=>true,'grants_revoked'=>true]);
    }
}
