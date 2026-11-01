<?php
declare(strict_types=1);
namespace DormCheck;

use PDO;
use RuntimeException;

final class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'VALIDATION_ERROR', public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}

interface Clock
{
    public function now(): string;
}

final class Support
{
    public static function now(array $config = []): string
    {
        // An injected clock is an internal dependency, never decoded from HTTP JSON.
        if (($config['clock'] ?? null) instanceof Clock) return $config['clock']->now();
        // A deterministic clock is allowed only in CLI integration tests, never an HTTP input.
        if (PHP_SAPI === 'cli' && isset($config['now'])) {
            return (new \DateTimeImmutable($config['now'], new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
        }
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
    }
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    public static function one(PDO $db, string $sql, array $params = []): ?array
    {
        $s = $db->prepare($sql); $s->execute($params); $r = $s->fetch();
        return $r === false ? null : $r;
    }
    public static function all(PDO $db, string $sql, array $params = []): array
    {
        $s = $db->prepare($sql); $s->execute($params); return $s->fetchAll();
    }
    public static function exec(PDO $db, string $sql, array $params = []): int
    {
        $s = $db->prepare($sql); $s->execute($params); return $s->rowCount();
    }
    public static function text(mixed $value, int $max = 500): string
    {
        if (!is_string($value) && !is_numeric($value)) throw new ApiError('字段格式不正确');
        $v = trim((string)$value);
        if (!mb_check_encoding($v, 'UTF-8') || mb_strlen($v, 'UTF-8') > $max || str_contains($v, "\0")) throw new ApiError('字段过长或含无效字符');
        return $v;
    }
}

final class Audit
{
    public static function write(PDO $db, ?int $actor, string $action, array $detail = []): void
    {
        // Callers must never put secrets or credentials in detail.
        Support::exec($db, 'INSERT INTO audit_logs (actor_id, action, detail_json, created_at) VALUES (?,?,?,?)', [$actor, $action, Support::json($detail), Support::now()]);
    }
}

final class Database
{
    public static function connect(array $config): PDO
    {
        $db = new PDO($config['db_dsn'], $config['db_user'] ?? '', $config['db_password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec('PRAGMA foreign_keys=ON'); $db->exec('PRAGMA busy_timeout=10000'); $db->exec('PRAGMA journal_mode=WAL');
        } else {
            $db->exec("SET time_zone = '+08:00'");
        }
        return $db;
    }
    public static function install(PDO $db): void
    {
        $id = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'BIGINT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tables = [
            "CREATE TABLE IF NOT EXISTS users (id $id, student_no VARCHAR(64) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL, role VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', password_hash VARCHAR(255), auth_version INTEGER NOT NULL DEFAULT 1, totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0, totp_last_step BIGINT NOT NULL DEFAULT -1, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS auth_tokens (id $id, user_id BIGINT NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, token_type VARCHAR(20) NOT NULL, expires_at BIGINT NOT NULL, used_at BIGINT, created_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS recovery_codes (id $id, user_id BIGINT NOT NULL, code_hash VARCHAR(64) NOT NULL UNIQUE, used_at BIGINT)",
            'CREATE TABLE IF NOT EXISTS rate_limits (bucket VARCHAR(64) PRIMARY KEY, window_start BIGINT NOT NULL, attempts INTEGER NOT NULL, blocked_until BIGINT NOT NULL)',
            "CREATE TABLE IF NOT EXISTS audit_logs (id $id, actor_id BIGINT, action VARCHAR(100) NOT NULL, detail_json TEXT NOT NULL, created_at VARCHAR(19) NOT NULL)",
        ];
        foreach ($tables as $sql) $db->exec($sql);
    }
}

final class Security
{
    public static function versionSafe(): bool
    {
        return (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 && version_compare(PHP_VERSION, '8.4.27', '>='))
            || (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION >= 5 && version_compare(PHP_VERSION, '8.5.12', '>='));
    }
    public static function password(string $password, string $studentNo = ''): string
    {
        $n = mb_strlen($password, 'UTF-8');
        if (!mb_check_encoding($password, 'UTF-8') || $n < 15 || $n > 64 || str_contains($password, "\0")) throw new ApiError('密码请使用 15—64 个字符，可使用容易记住的长句');
        $weak = ['123456789012345', 'passwordpassword', 'adminadminadmin', 'qwertyuiopasdfgh', '111111111111111'];
        if (in_array(strtolower($password), $weak, true) || preg_match('/^(.)\1+$/us', $password) || ($studentNo !== '' && str_contains($password, $studentNo))) throw new ApiError('请更换较强密码，避免重复字符、常见密码或包含学号');
        if (!defined('PASSWORD_ARGON2ID')) throw new RuntimeException('Argon2id support is required');
        return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
    }
    private static function key(array $config): string
    {
        $key = base64_decode($config['app_key'] ?? '', true);
        if ($key === false || strlen($key) !== 32) throw new RuntimeException('Missing or invalid app key');
        return $key;
    }
    public static function encrypt(string $plain, array $config): string
    {
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key($config), OPENSSL_RAW_DATA, $iv, $tag, 'DormCheck:v1');
        if ($cipher === false) throw new RuntimeException('Encryption failed');
        return base64_encode($iv . $tag . $cipher);
    }
    public static function decrypt(string $value, array $config): string
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Invalid encrypted value');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($config), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'DormCheck:v1');
        if ($plain === false) throw new RuntimeException('Invalid encryption key or data');
        return $plain;
    }
    public static function base32(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
        foreach (str_split($bytes) as $b) $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }
    public static function unbase32(string $text): string
    {
        $bits = ''; $out = ''; $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $c) {
            $v = strpos($alphabet, $c); if ($v === false) throw new RuntimeException('Invalid TOTP secret');
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        return $out;
    }
    // RFC 6238 TOTP, built from PHP's HMAC primitive; verified with RFC test vectors.
    public static function totp(string $secret, int $timestamp, int $digits = 6): string
    {
        $step = intdiv($timestamp, 30); $binary = pack('N2', intdiv($step, 4294967296), $step % 4294967296);
        $mac = hash_hmac('sha1', $binary, self::unbase32($secret), true); $offset = ord($mac[19]) & 15;
        $n = unpack('N', substr($mac, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string)($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
    public static function totpStep(string $secret, string $code, int $time, int $lastStep = -1): ?int
    {
        if (!preg_match('/^\d{6}$/D', $code)) return null;
        foreach ([-1, 0, 1] as $offset) {
            $t = $time + $offset * 30; $step = intdiv($t, 30);
            if ($step > $lastStep && hash_equals(self::totp($secret, $t), $code)) return $step;
        }
        return null;
    }
}
