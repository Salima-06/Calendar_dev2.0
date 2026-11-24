<?php
// Brightspace calendar feed (.ics) support: fetch it safely and read its events.
declare(strict_types=1);

// Only an https link on carleton.ca is allowed, so this can never be used to reach other sites or
// anything inside the server's own network.
function ics_check_url(string $raw): string {
    $raw = trim($raw);
    $raw = preg_replace('~^webcal:~i', 'https:', $raw);
    $p = parse_url($raw);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
        fail(422, 'That is not a valid link. Paste the https:// subscription link from Brightspace.');
    }
    $host = strtolower($p['host']);
    if (isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int)$p['port'] !== 443)
        || !preg_match('/(^|\.)carleton\.ca\z/', $host) || filter_var($host, FILTER_VALIDATE_IP)) {
        fail(422, 'Only https links on carleton.ca (your Brightspace feed) are allowed.');
    }
    return $raw;
}

function ics_fetch(string $url): string {
    if (!function_exists('curl_init')) {
        fail(503, 'This server cannot fetch calendar links (PHP curl is off).');
    }
    $buf = '';
    $tooBig = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => CARLETON_UA, CURLOPT_ENCODING => '',
        CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$buf, &$tooBig) {
            $buf .= $data;
            if (strlen($buf) > 8 * 1024 * 1024) {
                $tooBig = true;
                return 0;
            }
            return strlen($data);
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($tooBig) {
        fail(422, 'That calendar feed is too large.');
    }
    if ($ok === false) {
        fail(502, 'Could not reach Brightspace: ' . $err);
    }
    if ($code >= 300 && $code < 400) {
        fail(502, 'Brightspace redirected the request (probably to a login page). Re-copy the subscription link.');
    }
    if ($code < 200 || $code >= 300) {
        fail(502, "Brightspace answered HTTP $code. The link may be wrong or expired.");
    }
    if (stripos($buf, 'BEGIN:VCALENDAR') === false) {
        fail(502, 'That link did not return a calendar feed.');
    }
    return $buf;
}

function ics_unescape(string $v): string {
    return preg_replace_callback('/\\\\([nN,;\\\\])/', fn($m) => ($m[1] === 'n' || $m[1] === 'N') ? "\n" : $m[1], $v);
}

// Splits "DTSTART;TZID=America/Toronto:20261125T235900" into [name, params, value].
function ics_split_line(string $line): ?array {
    $inQuote = false;
    $colon = -1;
    $len = strlen($line);
    for ($i = 0; $i < $len; $i++) {
        $ch = $line[$i];
        if ($ch === '"') {
            $inQuote = !$inQuote;
        } elseif ($ch === ':' && !$inQuote) {
            $colon = $i;
            break;
        }
    }
    if ($colon < 1) {
        return null;
    }
    $head = substr($line, 0, $colon);
    $value = substr($line, $colon + 1);
    $parts = explode(';', $head);
    $name = strtoupper(array_shift($parts));
    $params = [];
    foreach ($parts as $p) {
        $kv = explode('=', $p, 2);
        $params[strtoupper($kv[0])] = trim($kv[1] ?? '', '"');
    }
    return [$name, $params, $value];
}

function ics_tz(?string $name, DateTimeZone $fallback): DateTimeZone {
    if ($name) {
        try {
            return new DateTimeZone($name);
        } catch (Throwable $e) {
        }
    }
    return $fallback;
}

// Returns ['date' => 'Y-m-d', 'time' => 'H:i'|null, 'allDay' => bool] in the person's own time zone.
function ics_when(string $value, array $params, DateTimeZone $user): ?array {
    $value = trim($value);
    if (($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}\z/', $value)) {
        $d = DateTime::createFromFormat('!Ymd', substr($value, 0, 8));
        return $d ? ['date' => $d->format('Y-m-d'), 'time' => null, 'allDay' => true] : null;
    }
    if (!preg_match('/^(\d{8})T(\d{4})(\d{2})?(Z?)\z/', $value, $m)) {
        return null;
    }
    $tz = $m[4] === 'Z' ? new DateTimeZone('UTC') : ics_tz($params['TZID'] ?? null, $user);
    $d = DateTime::createFromFormat('!Ymd\THis', $m[1] . 'T' . $m[2] . ($m[3] !== '' ? $m[3] : '00'), $tz);
    if (!$d) {
        return null;
    }
    $d->setTimezone($user);
    return ['date' => $d->format('Y-m-d'), 'time' => $d->format('H:i'), 'allDay' => false];
}

function ics_parse(string $text, DateTimeZone $user): array {
    $text = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $text));
    $events = [];
    $cur = null;
    $depth = 0;                                           // inside a nested block such as VALARM
    foreach (explode("\n", $text) as $line) {
        $line = rtrim($line, "\r");
        if (strcasecmp($line, 'BEGIN:VEVENT') === 0) {
            $cur = [];
            $depth = 0;
            continue;
        }
        if ($cur === null) {
            continue;
        }
        if (strcasecmp($line, 'END:VEVENT') === 0) {
            $e = ics_finish($cur, $user);
            if ($e) {
                $events[] = $e;
            }
            $cur = null;
            continue;
        }
        if (stripos($line, 'BEGIN:') === 0) {
            $depth++;
            continue;
        }
        if (stripos($line, 'END:') === 0) {
            $depth = max(0, $depth - 1);
            continue;
        }
        if ($depth > 0) {
            continue;
        }
        $parts = ics_split_line($line);
        if ($parts && !isset($cur[$parts[0]])) {
            $cur[$parts[0]] = [$parts[1], $parts[2]];
        }
    }
    return $events;
}

function ics_finish(array $p, DateTimeZone $user): ?array {
    if (!isset($p['DTSTART']) || strtoupper(trim($p['STATUS'][1] ?? '')) === 'CANCELLED') {
        return null;
    }
    $s = ics_when($p['DTSTART'][1], $p['DTSTART'][0], $user);
    if (!$s) {
        return null;
    }
    $e = isset($p['DTEND']) ? ics_when($p['DTEND'][1], $p['DTEND'][0], $user) : null;
    $endDate = null;
    $endTime = null;
    if ($s['allDay']) {
        if ($e && $e['allDay']) {                         // an all-day end date is "up to, not including"
            $last = (new DateTime($e['date']))->modify('-1 day')->format('Y-m-d');
            if ($last > $s['date']) {
                $endDate = $last;
            }
        }
    } elseif ($e && !$e['allDay'] && ($e['date'] . $e['time']) > ($s['date'] . $s['time'])) {
        $endDate = $e['date'] !== $s['date'] ? $e['date'] : null;
        $endTime = $e['time'];
    }
    $title = trim(ics_unescape($p['SUMMARY'][1] ?? ''));
    $uid = trim($p['UID'][1] ?? '');
    if ($uid === '') {
        $uid = sha1($title . '|' . $s['date'] . ($s['time'] ?? ''));
    }
    if (strlen($uid) > 120) {
        $uid = 'h-' . sha1($uid);
    }
    $loc = trim(ics_unescape($p['LOCATION'][1] ?? ''));
    return [
        'uid' => $uid,
        'title' => mb_substr($title !== '' ? $title : 'Untitled event', 0, 160),
        'notes' => mb_substr(trim(ics_unescape($p['DESCRIPTION'][1] ?? '')), 0, 1000),
        'location' => mb_substr($loc !== '' ? $loc : 'Online', 0, 200),
        'all_day' => $s['allDay'] ? 1 : 0,
        'date' => $s['date'],
        'start_time' => $s['allDay'] ? null : $s['time'] . ':00',
        'end_date' => $endDate,
        'end_time' => $endTime ? $endTime . ':00' : null,
    ];
}

// Saves parsed Brightspace events for one person. An event that is already saved (same Brightspace id)
// is updated in place and keeps the urgency the person gave it. Returns [saved, skippedBecauseOfLimit].
function ics_save(PDO $pdo, string $uid, array $list, int $maxEvents): array {
    $c = $pdo->prepare('SELECT COUNT(*) FROM events WHERE user_id = ?');
    $c->execute([$uid]);
    $have = (int)$c->fetchColumn();
    $known = [];
    $k = $pdo->prepare('SELECT uid FROM events WHERE user_id = ? AND uid IS NOT NULL');
    $k->execute([$uid]);
    foreach ($k->fetchAll() as $r) {
        $known[$r['uid']] = true;
    }
    $saved = 0;
    $skipped = 0;
    $ins = $pdo->prepare('INSERT INTO events (id, user_id, title, event_date, end_date, all_day, start_time, end_time, notes, location, urgency, source, uid)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE title = VALUES(title), event_date = VALUES(event_date), end_date = VALUES(end_date),
                            all_day = VALUES(all_day), start_time = VALUES(start_time), end_time = VALUES(end_time),
                            notes = VALUES(notes), location = VALUES(location)');
    $pdo->beginTransaction();
    try {
        foreach ($list as $e) {
            if (!isset($known[$e['uid']])) {
                if ($have >= $maxEvents) {
                    $skipped++;
                    continue;
                }
                $have++;
                $known[$e['uid']] = true;
            }
            $ins->execute([uuid4(), $uid, $e['title'], $e['date'], $e['end_date'], $e['all_day'], $e['start_time'], $e['end_time'],
                $e['notes'], $e['location'], 'chill', 'brightspace', $e['uid']]);
            $saved++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return [$saved, $skipped];
}
