<?php
declare(strict_types=1);
namespace DormCheck;

use PDO;

final class Schema
{
    public static function install(PDO $db): void
    {
        Database::install($db);
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'BIGINT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $json = $mysql ? 'LONGTEXT' : 'TEXT';
        $tables = [
            'CREATE TABLE IF NOT EXISTS app_lock (id INTEGER PRIMARY KEY, revision BIGINT NOT NULL DEFAULT 0)',
            "CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(100) PRIMARY KEY, value_json $json NOT NULL)",
            "CREATE TABLE IF NOT EXISTS rooms (id $id, building VARCHAR(100) NOT NULL, room_number VARCHAR(100) NOT NULL, UNIQUE(building,room_number))",
            "CREATE TABLE IF NOT EXISTS students (id $id, student_no VARCHAR(64) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL, class_name VARCHAR(100) NOT NULL, room_id BIGINT NOT NULL, leader_no VARCHAR(64) NOT NULL, active INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS placements (id $id, student_id BIGINT NOT NULL, room_id BIGINT NOT NULL, student_no VARCHAR(64) NOT NULL, name VARCHAR(100) NOT NULL, class_name VARCHAR(100) NOT NULL, leader_no VARCHAR(64) NOT NULL, valid_from VARCHAR(19) NOT NULL, valid_to VARCHAR(19))",
            "CREATE TABLE IF NOT EXISTS grants (id $id, user_id BIGINT NOT NULL, room_id BIGINT NOT NULL, valid_from VARCHAR(19) NOT NULL, valid_to VARCHAR(19), task_id BIGINT, revoked_at VARCHAR(19), actor_id BIGINT NOT NULL, created_at VARCHAR(19) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS exemptions (id $id, student_id BIGINT NOT NULL, start_date VARCHAR(10) NOT NULL, end_date VARCHAR(10) NOT NULL, source VARCHAR(200) NOT NULL, note TEXT NOT NULL, actor_id BIGINT NOT NULL, created_at VARCHAR(19) NOT NULL, revoked_at VARCHAR(19), revoke_reason TEXT)",
            "CREATE TABLE IF NOT EXISTS night_tasks (id $id, business_date VARCHAR(10) NOT NULL UNIQUE, check_at VARCHAR(19) NOT NULL, due_at VARCHAR(19) NOT NULL, phase VARCHAR(20) NOT NULL DEFAULT 'draft', frozen_at VARCHAR(19), roster_version INTEGER NOT NULL DEFAULT 1, `delayed` INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS room_tasks (task_id BIGINT NOT NULL, room_id BIGINT NOT NULL, building VARCHAR(100) NOT NULL, room_number VARCHAR(100) NOT NULL, leader_no VARCHAR(64) NOT NULL, leader_name VARCHAR(100) NOT NULL, responsible_json TEXT NOT NULL, phase VARCHAR(20) NOT NULL DEFAULT 'draft', roster_version INTEGER NOT NULL DEFAULT 1, report_version INTEGER NOT NULL DEFAULT 0, submitted_at VARCHAR(19), late INTEGER NOT NULL DEFAULT 0, closed_at VARCHAR(19), updated_at VARCHAR(19) NOT NULL, PRIMARY KEY(task_id,room_id))",
            "CREATE TABLE IF NOT EXISTS night_members (task_id BIGINT NOT NULL, room_id BIGINT NOT NULL, student_id BIGINT NOT NULL, student_no VARCHAR(64) NOT NULL, name VARCHAR(100) NOT NULL, class_name VARCHAR(100) NOT NULL, included INTEGER NOT NULL DEFAULT 1, exemption_json TEXT, initial_status VARCHAR(20) NOT NULL DEFAULT 'unreported', initial_location TEXT NOT NULL, corrected_initial_status VARCHAR(20), corrected_initial_location TEXT, current_status VARCHAR(20) NOT NULL DEFAULT 'unreported', location TEXT NOT NULL, expected_return_at VARCHAR(19), return_at VARCHAR(19), updated_at VARCHAR(19) NOT NULL, version INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(task_id,student_id))",
            "CREATE TABLE IF NOT EXISTS attendance_events (id $id, task_id BIGINT NOT NULL, room_id BIGINT NOT NULL, student_id BIGINT, type VARCHAR(40) NOT NULL, before_json $json NOT NULL, after_json $json NOT NULL, before_status VARCHAR(20), after_status VARCHAR(20), actor_id BIGINT, actor_name VARCHAR(100) NOT NULL, reason TEXT NOT NULL, occurred_at VARCHAR(19), recorded_at VARCHAR(19) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS request_keys (actor_id BIGINT NOT NULL, action VARCHAR(60) NOT NULL, token VARCHAR(100) NOT NULL, request_hash VARCHAR(64) NOT NULL, result_json $json NOT NULL, created_at VARCHAR(19) NOT NULL, PRIMARY KEY(actor_id,action,token))",
            "CREATE TABLE IF NOT EXISTS followups (id $id, task_id BIGINT NOT NULL, room_id BIGINT NOT NULL, student_id BIGINT NOT NULL, state VARCHAR(20) NOT NULL, note TEXT NOT NULL, actor_id BIGINT NOT NULL, actor_name VARCHAR(100) NOT NULL, created_at VARCHAR(19) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS inspections (id $id, task_id BIGINT NOT NULL, room_id BIGINT NOT NULL, result VARCHAR(100) NOT NULL, note TEXT NOT NULL, actor_id BIGINT NOT NULL, actor_name VARCHAR(100) NOT NULL, created_at VARCHAR(19) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS daily_reports (id $id, task_id BIGINT NOT NULL, version INTEGER NOT NULL, snapshot_json $json NOT NULL, reason TEXT NOT NULL, as_of VARCHAR(19) NOT NULL, created_at VARCHAR(19) NOT NULL, auto_key VARCHAR(100) UNIQUE, UNIQUE(task_id,version))",
            "CREATE TABLE IF NOT EXISTS drafts (task_id BIGINT NOT NULL, room_id BIGINT NOT NULL, actor_id BIGINT NOT NULL, roster_version INTEGER NOT NULL, content_json $json NOT NULL, updated_at VARCHAR(19) NOT NULL, PRIMARY KEY(task_id,room_id,actor_id))",
            "CREATE TABLE IF NOT EXISTS job_runs (id $id, job_name VARCHAR(60) NOT NULL, business_date VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, message TEXT NOT NULL, started_at VARCHAR(19) NOT NULL, finished_at VARCHAR(19) NOT NULL)",
        ];
        foreach ($tables as $sql) $db->exec($sql . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : ''));
        if (!Support::one($db, 'SELECT id FROM app_lock WHERE id=1')) {
            Support::exec($db, 'INSERT INTO app_lock (id,revision) VALUES (1,0)');
        }
        if (self::get($db, 'schema_version') === null) self::set($db, 'schema_version', 1);
    }

    public static function get(PDO $db, string $key, mixed $default = null): mixed
    {
        $row = Support::one($db, 'SELECT value_json FROM settings WHERE setting_key=?', [$key]);
        return $row ? json_decode($row['value_json'], true, 512, JSON_THROW_ON_ERROR) : $default;
    }

    public static function set(PDO $db, string $key, mixed $value): void
    {
        if (Support::one($db, 'SELECT setting_key FROM settings WHERE setting_key=?', [$key])) {
            Support::exec($db, 'UPDATE settings SET value_json=? WHERE setting_key=?', [Support::json($value), $key]);
        } else {
            Support::exec($db, 'INSERT INTO settings (setting_key,value_json) VALUES (?,?)', [$key, Support::json($value)]);
        }
    }

    public static function transaction(PDO $db, callable $work, bool $write = true): mixed
    {
        if ($db->inTransaction()) return $work();
        $db->beginTransaction();
        try {
            // One class needs no queue: serialize mutations before reading grants/versions.
            if ($write) $db->exec('UPDATE app_lock SET revision=revision+1 WHERE id=1');
            $result = $work();
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
