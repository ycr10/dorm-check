<?php
declare(strict_types=1);
use DormCheck\Database;
use DormCheck\ApiError;

require_once __DIR__ . '/Core.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/Grade.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Xlsx.php';
require_once __DIR__ . '/Reports.php';
require_once __DIR__ . '/Domain.php';

$configPath = getenv('DORM_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
if (!is_file($configPath)) throw new ApiError('系统尚未初始化，请先按部署文档运行 CLI 初始化', 'NOT_INSTALLED', 503);
$config = require $configPath;
if (!is_array($config)) throw new RuntimeException('Invalid configuration');
if (array_key_exists('clock', $config) || array_key_exists('now', $config)) throw new RuntimeException('Test clocks are not accepted by the application bootstrap');
$config += ['env' => 'production', 'timezone' => 'Asia/Shanghai', 'storage_path' => dirname(__DIR__) . '/storage', 'idle_seconds' => 1800, 'session_seconds' => 28800, 'reauth_seconds' => 300];
date_default_timezone_set('Asia/Shanghai');
if (!is_dir($config['storage_path'])) mkdir($config['storage_path'], 0700, true);
if (PHP_SAPI !== 'cli' && $config['env'] === 'production') {
    if (!DormCheck\Security::versionSafe()) throw new ApiError('PHP 安全版本不符合部署要求，请先升级', 'UNSAFE_RUNTIME', 503);
    if ((string)ini_get('session.upload_progress.enabled') !== '0' && ini_get('session.upload_progress.enabled') !== '') throw new ApiError('请在 php.ini 或 FPM 池关闭上传进度并重启工作进程', 'UNSAFE_CONFIGURATION', 503);
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') throw new ApiError('正式环境必须通过 HTTPS 访问', 'HTTPS_REQUIRED', 503);
}
if (PHP_SAPI !== 'cli' && empty($statelessPublic)) {
    $sessions = $config['storage_path'] . '/sessions'; if (!is_dir($sessions)) mkdir($sessions, 0700, true);
    session_save_path($sessions);
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    // Never change session.serialize_handler at runtime. Upload-progress mitigation is startup configuration.
    session_name($config['env'] === 'production' ? '__Host-DormSession' : 'DormSession');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $config['env'] === 'production', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$db = Database::connect($config);
return [$config, $db];
