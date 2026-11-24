<?php
// Ducks In A Row API. One file, called by the website like:  api/index.php?action=dashboard
// Every request must come from a signed-in Clerk user; each person only ever sees their own rows.
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/carleton.php';
require __DIR__ . '/ics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

set_exception_handler(function (Throwable $e) {
    error_log('[ducks-api] ' . $e);
    fail(500, 'Something went wrong on the server.');
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const YEARS = ['1st year', '2nd year', '3rd year', '4th year', '5th year or later', 'Graduate'];
const MAX_TASKS = 500;
const MAX_CLASSES = 300;
const MAX_EVENTS = 3000;
const MAX_GRADE_COURSES = 30;
const MAX_GRADE_ITEMS = 60;
const URGENCIES = ['urgent', 'soon', 'chill'];     // Duckin' Urgent, Quack in a Bit, No Need to Get Your Feathers Ruffled

$action = (string)($_GET['action'] ?? '');
$get_actions = ['me', 'dashboard', 'tasks', 'calendar', 'course_lookup', 'grades'];
$post_actions = ['save_onboarding', 'create_task', 'update_task', 'set_task_done', 'delete_task',
    'create_class', 'delete_class', 'create_event', 'update_event', 'delete_event', 'delete_event_series',
    'import_ics', 'add_course', 'delete_course', 'save_grade_course', 'delete_grade_course', 'set_newsletter'];

if (in_array($action, $get_actions, true)) {
    if ($method !== 'GET') {
        fail(405, 'Use GET for this request.');
    }
} elseif (in_array($action, $post_actions, true)) {
    if ($method !== 'POST') {
        fail(405, 'Use POST for this request.');
    }
} else {
    fail(404, 'Unknown request.');
}

$uid = current_user_id();
$pdo = db();
ensure_schema($pdo);
if (in_array($action, ['grades', 'save_grade_course', 'delete_grade_course'], true)) {
    ensure_grades_table($pdo);
}
if ($action === 'set_newsletter') {
    ensure_newsletter_schema($pdo);
}

// ---------- row shaping ----------
function shape_profile(?array $r): ?array {
    if (!$r) {
        return null;
    }
    $r['reminders_enabled'] = (bool)$r['reminders_enabled'];
    $r['onboarded'] = (bool)$r['onboarded'];
    if (array_key_exists('newsletter_opt_in', $r)) {
        $r['newsletter_opt_in'] = (bool)$r['newsletter_opt_in'];
    }
    unset($r['user_id']);
    return $r;
}
function shape_course(array $r): array {
    $labs = json_decode((string)$r['lab_sections'], true);
    $r['lab_sections'] = is_array($labs) ? $labs : [];
    unset($r['user_id']);
    return $r;
}
function shape_task(array $r): array {
    return [
        'id' => $r['id'], 'title' => $r['title'], 'due_date' => $r['due_date'],
        'due_time' => hhmm($r['due_time']), 'done' => (bool)$r['done'], 'done_at' => $r['done_at'] ?? null,
    ];
}
function shape_class(array $r): array {
    return [
        'id' => $r['id'], 'title' => $r['title'], 'days' => $r['days'] === '' ? [] : explode(',', $r['days']),
        'all_day' => (bool)$r['all_day'], 'start_time' => hhmm($r['start_time']), 'end_time' => hhmm($r['end_time']),
        'start_date' => $r['start_date'] ?? null, 'end_date' => $r['end_date'] ?? null,
        'course_key' => $r['course_key'] ?? null, 'section' => $r['section'] ?? null, 'kind' => $r['kind'] ?? null,
        'term' => $r['term'] ?? null, 'instructor' => $r['instructor'] ?? null, 'crn' => $r['crn'] ?? null,
        'source' => $r['source'] ?? 'manual',
    ];
}
function shape_event(array $r): array {
    return [
        'id' => $r['id'], 'title' => $r['title'], 'event_date' => $r['event_date'], 'end_date' => $r['end_date'] ?? null,
        'all_day' => (bool)$r['all_day'], 'start_time' => hhmm($r['start_time']), 'end_time' => hhmm($r['end_time']),
        'notes' => $r['notes'], 'location' => $r['location'] ?? null, 'urgency' => $r['urgency'] ?? 'chill',
        'source' => $r['source'] ?? 'manual', 'series_id' => $r['series_id'] ?? null,
    ];
}

function fetch_one(PDO $pdo, string $sql, array $args): ?array {
    $s = $pdo->prepare($sql);
    $s->execute($args);
    $r = $s->fetch();
    return $r ?: null;
}
function fetch_all(PDO $pdo, string $sql, array $args): array {
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return $s->fetchAll();
}
function count_rows(PDO $pdo, string $table, string $uid): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE user_id = ?");
    $s->execute([$uid]);
    return (int)$s->fetchColumn();
}

function bool_in($v): int {
    return ($v === true || $v === 1 || $v === '1') ? 1 : 0;
}

// Validates start/end times for classes and events.
function times_in(array $in, bool $all_day): array {
    if ($all_day) {
        return [null, null];
    }
    $start = v_time($in['start_time'] ?? null, 'Start time');
    $end = v_time($in['end_time'] ?? null, 'End time');
    if ($start === null) {
        fail(422, 'Start time is required (or choose "All day").');
    }
    if ($end !== null && $end <= $start) {
        fail(422, 'End time must be after the start time.');
    }
    return [$start, $end];
}

// Reads and checks the fields shared by create_event and update_event.
function event_fields(array $in): array {
    $title = v_str($in['title'] ?? '', 160, true, 'Title');
    $date = v_date($in['event_date'] ?? null, true, 'Date');
    $endDate = v_date($in['end_date'] ?? null, false, 'End date');
    if ($endDate !== null && $endDate < $date) {
        fail(422, 'The end date must be on or after the start date.');
    }
    if ($endDate === $date) {
        $endDate = null;
    }
    $all = bool_in($in['all_day'] ?? false);
    if ($all) {
        $start = $end = null;
    } else {
        $start = v_time($in['start_time'] ?? null, 'Start time');
        $end = v_time($in['end_time'] ?? null, 'End time');
        if ($start === null) {
            fail(422, 'Start time is required (or choose "All day").');
        }
        if ($endDate !== null && $end === null) {
            fail(422, 'Add an end time, or clear the end date.');
        }
        if ($end !== null && ($endDate ?? $date) . $end <= $date . $start) {
            fail(422, 'The end must be after the start.');
        }
    }
    $urgency = $in['urgency'] ?? 'chill';
    if (!in_array($urgency, URGENCIES, true)) {
        fail(422, 'Pick one of the three urgency levels.');
    }
    return [
        'title' => $title, 'date' => $date, 'end_date' => $endDate, 'all' => $all, 'start' => $start, 'end' => $end,
        'notes' => v_str($in['notes'] ?? '', 1000, false, 'Notes'), 'location' => v_str($in['location'] ?? '', 200, false, 'Location'),
        'urgency' => $urgency,
    ];
}

// "SYSC 1006" + term code -> [term, SUBJ, number], or a clear error.
function course_args($term, $code): array {
    $term = trim((string)$term);
    if (!preg_match('/^\d{6}\z/', $term)) {
        fail(422, 'Pick a term.');
    }
    if (!preg_match('/^\s*([A-Za-z]{3,4})\s*-?\s*(\d{4,5})\s*\z/', (string)$code, $m)) {
        fail(422, 'Course code should look like SYSC 1006.');
    }
    return [$term, strtoupper($m[1]), $m[2]];
}

// Stops one person from hammering an outside website: at most one call per $seconds for each kind.
function throttle(string $uid, string $kind, int $seconds): void {
    $dir = cfg()['cache_dir'] ?? (__DIR__ . '/cache');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $f = rtrim($dir, '/\\') . '/throttle-' . md5($kind . '|' . $uid);
    $last = is_file($f) ? (int)@file_get_contents($f) : 0;
    if (time() - $last < $seconds) {
        fail(429, 'Please wait a few seconds and try again.');
    }
    @file_put_contents($f, (string)time());
}

// The courses this person already has: the ones from onboarding, Carleton courses added on the calendar,
// and classes they typed in by hand. Used for the Grade Book's course list.
function known_course_names(PDO $pdo, string $uid): array {
    $names = [];
    $add = function ($n) use (&$names) {
        $n = trim((string)$n);
        if ($n !== '' && !isset($names[mb_strtolower($n)])) {
            $names[mb_strtolower($n)] = $n;
        }
    };
    foreach (fetch_all($pdo, 'SELECT course_code FROM courses WHERE user_id = ?', [$uid]) as $r) {
        $add(preg_replace('/\s*-?\s*(\d{4})$/', ' $1', (string)$r['course_code']));
    }
    foreach (fetch_all($pdo, 'SELECT DISTINCT course_key, title FROM classes WHERE user_id = ? LIMIT 300', [$uid]) as $r) {
        $add($r['course_key'] !== null && $r['course_key'] !== '' ? $r['course_key'] : $r['title']);
    }
    $list = array_values($names);
    natcasesort($list);
    return array_slice(array_values($list), 0, 80);
}
function shape_grade_course(array $r): array {
    $items = json_decode((string)$r['items'], true);
    return [
        'id' => $r['id'], 'name' => $r['name'], 'target' => (float)$r['target'], 'scen' => (float)$r['scen'],
        'items' => is_array($items) ? $items : [],
    ];
}
// A number between $min and $max, or null when left empty.
function v_num($v, float $min, float $max, string $label): ?float {
    if ($v === null || $v === '') {
        return null;
    }
    if (is_string($v)) {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
    }
    if (!is_numeric($v) || !is_finite((float)$v)) {
        fail(422, "$label must be a number.");
    }
    $n = (float)$v;
    if ($n < $min || $n > $max) {
        fail(422, "$label must be between " . rtrim(rtrim((string)$min, '0'), '.') . ' and ' . rtrim(rtrim((string)$max, '0'), '.') . '.');
    }
    return round($n, 2);
}
function clean_grade_items($raw): array {
    if (!is_array($raw) || count($raw) > MAX_GRADE_ITEMS) {
        fail(422, 'A course can have up to ' . MAX_GRADE_ITEMS . ' assessments.');
    }
    $items = [];
    $seen = [];
    foreach (array_values($raw) as $i => $it) {
        if (!is_array($it)) {
            fail(422, 'Invalid assessment.');
        }
        $id = isset($it['id']) && is_string($it['id']) && preg_match('/^[A-Za-z0-9_-]{1,24}$/', $it['id']) ? $it['id'] : 'i' . $i . substr(md5((string)mt_rand()), 0, 6);
        if (isset($seen[$id])) {
            $id .= $i;
        }
        $seen[$id] = true;
        $items[] = [
            'id' => $id,
            'name' => v_str($it['name'] ?? '', 80, false, 'Assessment name') ?? '',
            'weight' => v_num($it['weight'] ?? null, 0, 1000, 'Weight'),
            'score' => v_num($it['score'] ?? null, 0, 1000000, 'Score'),
            'out' => v_num($it['out'] ?? null, 0, 1000000, 'Out of'),
        ];
    }
    return $items;
}

// ---------- actions ----------
switch ($action) {
    case 'me':
        out([
            'profile' => shape_profile(fetch_one($pdo, 'SELECT * FROM profiles WHERE user_id = ?', [$uid])),
            'courses' => array_map('shape_course', fetch_all($pdo, 'SELECT * FROM courses WHERE user_id = ? ORDER BY created_at, course_code', [$uid])),
        ]);

    case 'tasks':
        // Everything the Task Manager page needs: open tasks plus the most recently finished ones.
        out([
            'profile' => shape_profile(fetch_one($pdo, 'SELECT * FROM profiles WHERE user_id = ?', [$uid])),
            'open' => array_map('shape_task', fetch_all(
                $pdo,
                'SELECT * FROM tasks WHERE user_id = ? AND done = 0
                 ORDER BY (due_date IS NULL), due_date, (due_time IS NULL), due_time, created_at LIMIT 500',
                [$uid]
            )),
            'done' => array_map('shape_task', fetch_all(
                $pdo,
                'SELECT * FROM tasks WHERE user_id = ? AND done = 1 ORDER BY done_at DESC, created_at DESC LIMIT 100',
                [$uid]
            )),
        ]);

    case 'dashboard':
        $from = v_date($_GET['from'] ?? date('Y-m-d'), true, 'From date');
        out([
            'profile' => shape_profile(fetch_one($pdo, 'SELECT * FROM profiles WHERE user_id = ?', [$uid])),
            'courses' => array_map('shape_course', fetch_all($pdo, 'SELECT * FROM courses WHERE user_id = ? ORDER BY created_at, course_code', [$uid])),
            'tasks' => array_map('shape_task', fetch_all(
                $pdo,
                'SELECT * FROM tasks WHERE user_id = ? AND done = 0
                 ORDER BY (due_date IS NULL), due_date, (due_time IS NULL), due_time, created_at LIMIT 100',
                [$uid]
            )),
            'classes' => array_map('shape_class', fetch_all(
                $pdo,
                'SELECT * FROM classes WHERE user_id = ? ORDER BY all_day DESC, start_time, title',
                [$uid]
            )),
            'events' => array_map('shape_event', fetch_all(
                $pdo,
                'SELECT * FROM events WHERE user_id = ? AND (event_date >= ? OR end_date >= ?)
                 ORDER BY event_date, all_day DESC, start_time, title LIMIT 100',
                [$uid, $from, $from]
            )),
        ]);

    case 'save_onboarding':
        $in = body();
        $p = is_array($in['profile'] ?? null) ? $in['profile'] : [];
        $year = (string)($p['year_of_study'] ?? '');
        if (!in_array($year, YEARS, true)) {
            fail(422, 'Please choose your year of study.');
        }
        $tz = (string)($p['timezone'] ?? '');
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            fail(422, 'Use a time zone like America/Toronto.');
        }
        $week = ($p['week_starts_on'] ?? '') === 'sunday' ? 'sunday' : 'monday';
        $profile = [
            'full_name' => v_str($p['full_name'] ?? '', 120, true, 'Name'),
            'school' => v_str($p['school'] ?? '', 120, true, 'School'),
            'program' => v_str($p['program'] ?? '', 120, false),
            'year' => $year, 'tz' => $tz, 'week' => $week,
            'rem' => bool_in($p['reminders_enabled'] ?? true),
        ];
        $term = v_str($in['term'] ?? '', 30, false);

        $courses = [];
        $raw = is_array($in['courses'] ?? null) ? $in['courses'] : [];
        if (count($raw) > 20) {
            fail(422, 'Too many courses (max 20).');
        }
        foreach ($raw as $c) {
            $code = strtoupper(trim((string)($c['course_code'] ?? '')));
            if (!preg_match('/^([A-Z]{3,4})\s*-?\s*(\d{4})$/', $code, $m)) {
                fail(422, 'Course codes look like COMP 1405.');
            }
            $labs = [];
            foreach ((array)($c['lab_sections'] ?? []) as $l) {
                $l = strtoupper(trim((string)$l));
                if ($l !== '' && !preg_match('/^[A-Z0-9]{1,6}$/', $l)) {
                    fail(422, 'Lab/tutorial sections use letters and numbers, like A1.');
                }
                if ($l !== '') {
                    $labs[] = $l;
                }
            }
            if (count($labs) > 12) {
                fail(422, 'Too many lab/tutorial sections for one course.');
            }
            $section = strtoupper(trim((string)($c['section'] ?? '')));
            if ($section !== '' && !preg_match('/^[A-Z0-9]{1,6}$/', $section)) {
                fail(422, 'Sections use letters and numbers, like A.');
            }
            $courses[] = [uuid4(), $m[1] . ' ' . $m[2], $section ?: null, json_encode($labs)];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO profiles (user_id, full_name, school, program, year_of_study, timezone, week_starts_on, reminders_enabled, onboarded)
                 VALUES (?,?,?,?,?,?,?,?,1)
                 ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), school=VALUES(school), program=VALUES(program),
                   year_of_study=VALUES(year_of_study), timezone=VALUES(timezone), week_starts_on=VALUES(week_starts_on),
                   reminders_enabled=VALUES(reminders_enabled), onboarded=1'
            )->execute([$uid, $profile['full_name'], $profile['school'], $profile['program'], $profile['year'],
                $profile['tz'], $profile['week'], $profile['rem']]);
            $pdo->prepare('DELETE FROM courses WHERE user_id = ?')->execute([$uid]);
            $ins = $pdo->prepare('INSERT INTO courses (id, user_id, course_code, section, lab_sections, term) VALUES (?,?,?,?,?,?)');
            foreach ($courses as $c) {
                $ins->execute([$c[0], $uid, $c[1], $c[2], $c[3], $term]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        out(['ok' => true]);

    case 'create_task':
        $in = body();
        $title = v_str($in['title'] ?? '', 160, true, 'Title');
        $date = v_date($in['due_date'] ?? null, false, 'Due date');
        $time = $date ? v_time($in['due_time'] ?? null, 'Due time') : null;
        $open = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE user_id = ? AND done = 0');
        $open->execute([$uid]);
        if ((int)$open->fetchColumn() >= MAX_TASKS) {
            fail(422, 'You have reached the limit of open tasks. Complete or delete some first.');
        }
        $id = uuid4();
        $pdo->prepare('INSERT INTO tasks (id, user_id, title, due_date, due_time) VALUES (?,?,?,?,?)')
            ->execute([$id, $uid, $title, $date, $time]);
        out(shape_task(fetch_one($pdo, 'SELECT * FROM tasks WHERE id = ? AND user_id = ?', [$id, $uid])), 201);

    case 'update_task':
        $in = body();
        $id = v_id($in['id'] ?? null);
        $title = v_str($in['title'] ?? '', 160, true, 'Title');
        $date = v_date($in['due_date'] ?? null, false, 'Due date');
        $time = $date ? v_time($in['due_time'] ?? null, 'Due time') : null;
        $st = $pdo->prepare('UPDATE tasks SET title = ?, due_date = ?, due_time = ? WHERE id = ? AND user_id = ?');
        $st->execute([$title, $date, $time, $id, $uid]);
        $row = fetch_one($pdo, 'SELECT * FROM tasks WHERE id = ? AND user_id = ?', [$id, $uid]);
        if (!$row) {
            fail(404, 'Task not found.');
        }
        out(shape_task($row));

    case 'set_task_done':
        $in = body();
        $id = v_id($in['id'] ?? null);
        $done = bool_in($in['done'] ?? true);
        $st = $pdo->prepare('UPDATE tasks SET done = ?, done_at = IF(?, NOW(), NULL) WHERE id = ? AND user_id = ?');
        $st->execute([$done, $done, $id, $uid]);
        if ($done) {
            // Keep only the 200 most recently finished tasks so the table can't grow forever.
            $old = fetch_all($pdo, 'SELECT id FROM tasks WHERE user_id = ? AND done = 1 ORDER BY done_at DESC, created_at DESC LIMIT 1000 OFFSET 200', [$uid]);
            foreach ($old as $o) {
                $pdo->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ? AND done = 1')->execute([$o['id'], $uid]);
            }
        }
        out(['ok' => true]);

    case 'delete_task':
    case 'delete_class':
    case 'delete_event':
        $in = body();
        $id = v_id($in['id'] ?? null);
        $table = ['delete_task' => 'tasks', 'delete_class' => 'classes', 'delete_event' => 'events'][$action];
        $st = $pdo->prepare("DELETE FROM `$table` WHERE id = ? AND user_id = ?");
        $st->execute([$id, $uid]);
        if ($st->rowCount() === 0) {
            fail(404, 'Item not found.');
        }
        out(['ok' => true]);

    case 'create_class':
        $in = body();
        $title = v_str($in['title'] ?? '', 80, true, 'Class name');
        $days = array_values(array_unique(array_filter((array)($in['days'] ?? []), fn($d) => in_array($d, DAYS, true))));
        if (!$days) {
            fail(422, 'Pick at least one day.');
        }
        usort($days, fn($a, $b) => array_search($a, DAYS, true) <=> array_search($b, DAYS, true));
        $all = bool_in($in['all_day'] ?? false);
        [$start, $end] = times_in($in, (bool)$all);
        if (count_rows($pdo, 'classes', $uid) >= MAX_CLASSES) {
            fail(422, 'You have reached the class limit.');
        }
        $id = uuid4();
        $pdo->prepare('INSERT INTO classes (id, user_id, title, days, all_day, start_time, end_time) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $uid, $title, implode(',', $days), $all, $start, $end]);
        out(shape_class(fetch_one($pdo, 'SELECT * FROM classes WHERE id = ? AND user_id = ?', [$id, $uid])), 201);

    case 'create_event':
        $in = body();
        $f = event_fields($in);
        $repeat = ($in['repeat'] ?? 'none') === 'weekly';
        $dates = [[$f['date'], $f['end_date']]];
        $capped = false;
        if ($repeat) {
            $until = v_date($in['repeat_until'] ?? null, true, 'Repeat until');
            if ($until < $f['date']) {
                fail(422, 'Pick a "repeat until" date on or after the first date.');
            }
            $dates = [];
            for ($i = 0; $i < 60; $i++) {
                $d = (new DateTime($f['date']))->modify('+' . (7 * $i) . ' days')->format('Y-m-d');
                if ($d > $until) {
                    break;
                }
                $e = $f['end_date'] ? (new DateTime($f['end_date']))->modify('+' . (7 * $i) . ' days')->format('Y-m-d') : null;
                $dates[] = [$d, $e];
            }
            $capped = (new DateTime($f['date']))->modify('+420 days')->format('Y-m-d') <= $until;
        }
        if (count_rows($pdo, 'events', $uid) + count($dates) > MAX_EVENTS) {
            fail(422, 'You have reached the event limit.');
        }
        $series = count($dates) > 1 ? uuid4() : null;
        $ids = [];
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO events (id, user_id, title, event_date, end_date, all_day, start_time, end_time, notes, location, urgency, source, series_id)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($dates as [$d, $e]) {
                $id = uuid4();
                $ids[] = $id;
                $ins->execute([$id, $uid, $f['title'], $d, $e, $f['all'], $f['start'], $f['end'], $f['notes'], $f['location'], $f['urgency'], 'manual', $series]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $first = shape_event(fetch_one($pdo, 'SELECT * FROM events WHERE id = ? AND user_id = ?', [$ids[0], $uid]));
        $first['count'] = count($ids);
        $first['capped'] = $capped;
        out($first, 201);

    case 'update_event':
        $in = body();
        $id = v_id($in['id'] ?? null);
        $f = event_fields($in);
        $st = $pdo->prepare('UPDATE events SET title = ?, event_date = ?, end_date = ?, all_day = ?, start_time = ?, end_time = ?,
                             notes = ?, location = ?, urgency = ? WHERE id = ? AND user_id = ?');
        $st->execute([$f['title'], $f['date'], $f['end_date'], $f['all'], $f['start'], $f['end'], $f['notes'], $f['location'], $f['urgency'], $id, $uid]);
        $row = fetch_one($pdo, 'SELECT * FROM events WHERE id = ? AND user_id = ?', [$id, $uid]);
        if (!$row) {
            fail(404, 'Event not found.');
        }
        out(shape_event($row));

    case 'delete_event_series':
        $in = body();
        $id = v_id($in['id'] ?? null);
        $row = fetch_one($pdo, 'SELECT series_id FROM events WHERE id = ? AND user_id = ?', [$id, $uid]);
        if (!$row) {
            fail(404, 'Event not found.');
        }
        if ($row['series_id']) {
            $st = $pdo->prepare('DELETE FROM events WHERE series_id = ? AND user_id = ?');
            $st->execute([$row['series_id'], $uid]);
            out(['ok' => true, 'deleted' => $st->rowCount()]);
        }
        $pdo->prepare('DELETE FROM events WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        out(['ok' => true, 'deleted' => 1]);

    case 'calendar':
        // Everything the Calendar page draws: one-off events, and weekly classes (including Carleton courses).
        out([
            'profile' => shape_profile(fetch_one($pdo, 'SELECT * FROM profiles WHERE user_id = ?', [$uid])),
            'events' => array_map('shape_event', fetch_all(
                $pdo,
                'SELECT * FROM events WHERE user_id = ? ORDER BY event_date, all_day DESC, start_time, title LIMIT ' . MAX_EVENTS,
                [$uid]
            )),
            'classes' => array_map('shape_class', fetch_all(
                $pdo,
                'SELECT * FROM classes WHERE user_id = ? ORDER BY course_key, section, start_time LIMIT ' . MAX_CLASSES,
                [$uid]
            )),
        ]);

    case 'import_ics':
        // Reads the person's Brightspace calendar link and saves its events (same event again = updated, not doubled).
        $in = body();
        $url = ics_check_url((string)($in['url'] ?? ''));
        throttle($uid, 'ics', 5);
        $prof = fetch_one($pdo, 'SELECT timezone FROM profiles WHERE user_id = ?', [$uid]);
        $tz = ics_tz($prof['timezone'] ?? null, new DateTimeZone('America/Toronto'));
        $list = ics_parse(ics_fetch($url), $tz);
        if (!$list) {
            out(['found' => 0, 'saved' => 0, 'skipped' => 0]);
        }
        [$saved, $skipped] = ics_save($pdo, $uid, $list, MAX_EVENTS);
        out(['found' => count($list), 'saved' => $saved, 'skipped' => $skipped]);

    case 'course_lookup':
        [$term, $subj, $num] = course_args($_GET['term'] ?? '', $_GET['code'] ?? '');
        throttle($uid, 'carleton', 2);
        try {
            $r = carleton_lookup($term, $subj, $num);
        } catch (RuntimeException $e) {
            fail(502, $e->getMessage() === 'NO_CURL' ? 'This server cannot reach Carleton (PHP curl is off).' : $e->getMessage());
        }
        out(['course' => "$subj $num", 'term' => $term, 'sections' => $r['sections'], 'warning' => $r['warning']]);

    case 'add_course':
        // Looks the course up at Carleton, keeps the lecture + lab/tutorial sections the person picked,
        // and saves their weekly meeting times as classes.
        $in = body();
        [$term, $subj, $num] = course_args($in['term'] ?? '', $in['code'] ?? '');
        $key = "$subj $num";
        $lec = trim((string)($in['lecture'] ?? ''));
        $labs = $in['labs'] ?? [];
        $labs = is_array($labs) ? $labs : preg_split('/[,\s]+/', (string)$labs);
        $wanted = array_values(array_filter(array_map(fn($w) => carleton_norm((string)$w), array_merge([$lec], $labs)), fn($w) => $w !== ''));
        if (!$wanted) {
            fail(422, 'Enter the lecture section (for example A).');
        }
        if (count($wanted) > 8) {
            fail(422, 'That is too many sections.');
        }
        throttle($uid, 'carleton', 2);
        try {
            $r = carleton_lookup($term, $subj, $num);
        } catch (RuntimeException $e) {
            fail(502, $e->getMessage() === 'NO_CURL' ? 'This server cannot reach Carleton (PHP curl is off).' : $e->getMessage());
        }
        if (!$r['sections']) {
            fail(404, $r['warning'] ?: 'No sections found.');
        }
        $picked = [];
        $missing = [];
        foreach ($wanted as $w) {
            $hit = null;
            foreach ($r['sections'] as $sec) {
                if (carleton_norm($sec['section']) === $w) {
                    $hit = $sec;
                    break;
                }
            }
            $hit ? $picked[$hit['crn']] = $hit : $missing[] = $w;
        }
        if (!$picked) {
            $avail = implode(', ', array_map(fn($x) => $x['section'], $r['sections']));
            fail(404, "Couldn't find " . implode(', ', $wanted) . ". Sections in the schedule: $avail");
        }
        $rows = [];
        foreach ($picked as $sec) {
            foreach ($sec['meetings'] as $m) {
                $days = array_values(array_unique(array_map(fn($d) => strtolower($d), $m['days'])));
                $days = array_values(array_filter($days, fn($d) => in_array($d, DAYS, true)));
                if (!$days || !$m['start'] || !$m['end']) {
                    continue;
                }
                $rows[] = [$sec, $m, $days];
            }
        }
        $old = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE user_id = ? AND course_key = ? AND term = ? AND source = 'carleton'");
        $old->execute([$uid, $key, $term]);
        if (count_rows($pdo, 'classes', $uid) - (int)$old->fetchColumn() + count($rows) > MAX_CLASSES) {
            fail(422, 'You have reached the class limit.');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM classes WHERE user_id = ? AND course_key = ? AND term = ? AND source = 'carleton'")->execute([$uid, $key, $term]);
            $ins = $pdo->prepare('INSERT INTO classes (id, user_id, title, days, all_day, start_time, end_time, course_key, section, kind, term, start_date, end_date, instructor, crn, source)
                                  VALUES (?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($rows as [$sec, $m, $days]) {
                usort($days, fn($a, $b) => array_search($a, DAYS, true) <=> array_search($b, DAYS, true));
                $title = mb_substr($key . ' ' . $sec['section'] . ($sec['type'] !== '' ? ' (' . $sec['type'] . ')' : ''), 0, 80);
                $ins->execute([uuid4(), $uid, $title, implode(',', $days), $m['start'] . ':00', $m['end'] . ':00', $key,
                    mb_substr($sec['section'], 0, 12), mb_substr($sec['type'], 0, 40), $term, $m['startDate'], $m['endDate'],
                    mb_substr($sec['instructor'], 0, 120), mb_substr($sec['crn'], 0, 12), 'carleton']);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        out([
            'course' => $key, 'term' => $term, 'sections' => array_values(array_map(fn($x) => $x['section'], $picked)),
            'meetings' => count($rows), 'missing' => $missing,
            'note' => $rows ? null : 'Carleton lists no meeting times for that section (it may be online or still to be scheduled).',
        ], 201);

    case 'grades':
        out([
            'profile' => shape_profile(fetch_one($pdo, 'SELECT * FROM profiles WHERE user_id = ?', [$uid])),
            'courses' => array_map('shape_grade_course', fetch_all(
                $pdo,
                'SELECT * FROM grade_courses WHERE user_id = ? ORDER BY position, created_at LIMIT ' . MAX_GRADE_COURSES,
                [$uid]
            )),
            'known_courses' => known_course_names($pdo, $uid),
        ]);

    case 'save_grade_course':
        // Creates the course when no id is sent, otherwise replaces that course's name, goals and assessments.
        $in = body();
        $name = v_str($in['name'] ?? '', 80, true, 'Course name');
        $target = v_num($in['target'] ?? null, 0, 100, 'Target grade') ?? 80.0;
        $scen = v_num($in['scen'] ?? null, 0, 100, 'Average on remaining work') ?? 75.0;
        $items = json_encode(clean_grade_items($in['items'] ?? []), JSON_UNESCAPED_UNICODE);
        if (!empty($in['id'])) {
            $id = v_id($in['id']);
            $st = $pdo->prepare('UPDATE grade_courses SET name = ?, target = ?, scen = ?, items = ? WHERE id = ? AND user_id = ?');
            $st->execute([$name, $target, $scen, $items, $id, $uid]);
            $row = fetch_one($pdo, 'SELECT * FROM grade_courses WHERE id = ? AND user_id = ?', [$id, $uid]);
            if (!$row) {
                fail(404, 'Course not found.');
            }
            out(shape_grade_course($row));
        }
        $n = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(position), 0) FROM grade_courses WHERE user_id = ?');
        $n->execute([$uid]);
        [$count, $maxPos] = $n->fetch(PDO::FETCH_NUM);
        if ((int)$count >= MAX_GRADE_COURSES) {
            fail(422, 'You have reached the limit of ' . MAX_GRADE_COURSES . ' courses. Delete one first.');
        }
        $id = uuid4();
        $pdo->prepare('INSERT INTO grade_courses (id, user_id, name, target, scen, items, position) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $uid, $name, $target, $scen, $items, (int)$maxPos + 1]);
        out(shape_grade_course(fetch_one($pdo, 'SELECT * FROM grade_courses WHERE id = ? AND user_id = ?', [$id, $uid])), 201);

    case 'set_newsletter':
        // Turns the Sunday email on or off for the signed-in person (for the Account page's switch).
        $in = body();
        $on = !empty($in['on']) ? 1 : 0;
        $st = $pdo->prepare('UPDATE profiles SET newsletter_opt_in = ? WHERE user_id = ?');
        $st->execute([$on, $uid]);
        out(['newsletter_opt_in' => (bool)$on]);

    case 'delete_grade_course':
        $in = body();
        $id = v_id($in['id'] ?? null);
        $st = $pdo->prepare('DELETE FROM grade_courses WHERE id = ? AND user_id = ?');
        $st->execute([$id, $uid]);
        if ($st->rowCount() === 0) {
            fail(404, 'Course not found.');
        }
        out(['ok' => true]);

    case 'delete_course':
        $in = body();
        $key = v_str($in['course_key'] ?? '', 20, true, 'Course');
        $term = v_str($in['term'] ?? '', 8, false, 'Term');
        $sql = "DELETE FROM classes WHERE user_id = ? AND course_key = ? AND source = 'carleton'" . ($term ? ' AND term = ?' : '');
        $st = $pdo->prepare($sql);
        $st->execute($term ? [$uid, $key, $term] : [$uid, $key]);
        out(['ok' => true, 'deleted' => $st->rowCount()]);
}
