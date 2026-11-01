<?php
declare(strict_types=1);
// Copy via `php bin/console.php init`; keep local.php outside the public web root.
return [
    'env' => 'production',
    'app_url' => 'https://dorm.example.edu',
    'class_name' => '示例班级',
    'timezone' => 'Asia/Shanghai',
    'check_time' => '22:30',
    'due_time' => '22:35',
    'db_dsn' => getenv('DORM_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=dorm_check;charset=utf8mb4',
    'db_user' => getenv('DORM_DB_USER') ?: 'dorm_app',
    'db_password' => getenv('DORM_DB_PASSWORD') ?: '',
    'app_key' => getenv('DORM_APP_KEY') ?: '', // 32 random bytes, base64 encoded.
    'storage_path' => dirname(__DIR__) . '/storage',
    'idle_seconds' => 1800,
    'session_seconds' => 28800,
    'reauth_seconds' => 300,
];
