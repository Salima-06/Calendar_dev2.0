<?php
// Brings an older database up to date (adds the columns the calendar needs). Safe to run any number of
// times: it only changes things that are missing. If your database user is not allowed to change tables,
// run ionos-update-calendar.sql once in phpMyAdmin instead.
declare(strict_types=1);

function schema_flag_path(): string {
    $dir = cfg()['cache_dir'] ?? (__DIR__ . '/cache');
    return rtrim($dir, '/\\') . '/schema-v2.ok';
}

function ensure_schema(PDO $pdo): void {
    $flag = schema_flag_path();
    if (is_file($flag)) {
        return;
    }
    $have = [];
    $s = $pdo->query("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('events','classes')");
    foreach ($s->fetchAll() as $r) {
        $have[strtolower($r['t'])][strtolower($r['c'])] = true;
    }
    if (empty($have['events']) || empty($have['classes'])) {
        fail(503, 'The database tables are missing. Import ionos-schema.sql in phpMyAdmin first.');
    }
    $idx = [];
    $s = $pdo->query("SELECT TABLE_NAME t, INDEX_NAME i FROM information_schema.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('events','classes')");
    foreach ($s->fetchAll() as $r) {
        $idx[strtolower($r['t'])][strtolower($r['i'])] = true;
    }

    $add = [
        'events' => [
            'end_date'  => 'DATE NULL AFTER event_date',
            'urgency'   => "VARCHAR(8) NOT NULL DEFAULT 'chill'",
            'location'  => 'VARCHAR(200) NULL',
            'source'    => "VARCHAR(12) NOT NULL DEFAULT 'manual'",
            'uid'       => 'VARCHAR(120) NULL',
            'series_id' => 'CHAR(36) NULL',
        ],
        'classes' => [
            'course_key' => 'VARCHAR(20) NULL',
            'section'    => 'VARCHAR(12) NULL',
            'kind'       => 'VARCHAR(40) NULL',
            'term'       => 'VARCHAR(8) NULL',
            'start_date' => 'DATE NULL',
            'end_date'   => 'DATE NULL',
            'instructor' => 'VARCHAR(120) NULL',
            'crn'        => 'VARCHAR(12) NULL',
            'source'     => "VARCHAR(12) NOT NULL DEFAULT 'manual'",
        ],
    ];
    try {
        foreach ($add as $table => $cols) {
            foreach ($cols as $col => $def) {
                if (empty($have[$table][$col])) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                }
            }
        }
        if (empty($idx['events']['events_uid_idx'])) {
            $pdo->exec('ALTER TABLE `events` ADD UNIQUE KEY `events_uid_idx` (`user_id`, `uid`)');
        }
        if (empty($idx['classes']['classes_course_idx'])) {
            $pdo->exec('ALTER TABLE `classes` ADD KEY `classes_course_idx` (`user_id`, `course_key`)');
        }
    } catch (Throwable $e) {
        error_log('[ducks-api] schema update failed: ' . $e->getMessage());
        fail(503, 'The database needs a small update. Run ionos-update-calendar.sql in phpMyAdmin, then try again.');
    }
    $dir = dirname($flag);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($flag, date('c'));
}


// Grade Book table (added after the calendar update). Created the first time it is needed.
function ensure_grades_table(PDO $pdo): void {
    $dir = cfg()['cache_dir'] ?? (__DIR__ . '/cache');
    $flag = rtrim($dir, '/\\') . '/schema-v3.ok';
    if (is_file($flag)) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS grade_courses (
            id         CHAR(36)     NOT NULL,
            user_id    VARCHAR(64)  NOT NULL,
            name       VARCHAR(80)  NOT NULL,
            target     DECIMAL(5,1) NOT NULL DEFAULT 80.0,
            scen       DECIMAL(5,1) NOT NULL DEFAULT 75.0,
            items      TEXT         NOT NULL,
            position   INT          NOT NULL DEFAULT 0,
            created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY grade_courses_user_idx (user_id, position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[ducks-api] grade table failed: ' . $e->getMessage());
        fail(503, 'The Grade Book needs a small database update. Run ionos-update-grades.sql in phpMyAdmin, then try again.');
    }
    if (!is_dir(dirname($flag))) {
        @mkdir(dirname($flag), 0755, true);
    }
    @file_put_contents($flag, date('c'));
}


// Weekly newsletter: an opt-in flag on each profile and a log so nobody gets the same week's email twice.
function ensure_newsletter_schema(PDO $pdo): void {
    $dir = cfg()['cache_dir'] ?? (__DIR__ . '/cache');
    $flag = rtrim($dir, '/\\') . '/schema-v4.ok';
    if (is_file($flag)) {
        return;
    }
    try {
        $s = $pdo->query("SELECT COLUMN_NAME c FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'newsletter_opt_in'");
        if (!$s->fetch()) {
            $pdo->exec('ALTER TABLE `profiles` ADD COLUMN `newsletter_opt_in` TINYINT(1) NOT NULL DEFAULT 1');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS newsletter_sends (
            user_id    VARCHAR(64) NOT NULL,
            week_key   CHAR(10)    NOT NULL,
            status     VARCHAR(10) NOT NULL DEFAULT 'sending',
            attempts   INT         NOT NULL DEFAULT 0,
            detail     VARCHAR(255) NULL,
            created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, week_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[ducks-api] newsletter schema failed: ' . $e->getMessage());
        fail(503, 'The newsletter needs a small database update. Run ionos-update-newsletter.sql in phpMyAdmin, then try again.');
    }
    if (!is_dir(dirname($flag))) {
        @mkdir(dirname($flag), 0755, true);
    }
    @file_put_contents($flag, date('c'));
}
