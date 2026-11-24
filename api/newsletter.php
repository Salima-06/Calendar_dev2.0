<?php
// Weekly newsletter ("Your week ahead"): builds the email from each person's tasks, events and the
// weather, and sends it through Resend every Sunday at 3:33 pm in the person's own time zone.
// Used by cron-newsletter.php (the scheduled job) only. Nothing here is reachable from the website.
declare(strict_types=1);

const NL_HOUR = 15;          // 3:33 pm
const NL_MINUTE = 33;
const NL_DAYS = 7;           // today (Sunday) through next Saturday
const NL_MAX_ITEMS = 25;
const NL_MAX_ATTEMPTS = 3;

function nl_cfg(string $key, $default = null) {
    $v = cfg()[$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

// ---------- when ----------
function nl_tz(?string $name): DateTimeZone {
    try {
        return new DateTimeZone($name ?: 'America/Toronto');
    } catch (Throwable $e) {
        return new DateTimeZone('America/Toronto');
    }
}
// The Sunday this email belongs to, e.g. "2026-11-29", or null when it is not Sunday in that person's zone.
function nl_week_key(DateTimeImmutable $local): ?string {
    return $local->format('w') === '0' ? $local->format('Y-m-d') : null;
}
// Is it time to send? From Sunday 3:33 pm until the catch-up window closes (so one missed cron run is not fatal).
function nl_is_due(DateTimeImmutable $nowUtc, DateTimeZone $tz): ?string {
    $local = $nowUtc->setTimezone($tz);
    $key = nl_week_key($local);
    if ($key === null) {
        return null;
    }
    $start = $local->setTime(NL_HOUR, NL_MINUTE, 0);
    $window = max(1, (int)nl_cfg('newsletter_window_hours', 6));
    $end = $start->modify("+$window hours");
    return ($local >= $start && $local < $end) ? $key : null;
}

// ---------- content ----------
function nl_first_name(?string $full): string {
    $full = trim((string)$full);
    if ($full === '') {
        return 'duck';
    }
    $first = preg_split('/\s+/u', $full)[0];
    return mb_substr($first, 0, 40);
}

function nl_time12(?string $t): string {
    if (!$t) {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('H:i:s', strlen($t) === 5 ? $t . ':00' : $t);
    return $d ? ltrim($d->format('g:i A'), '0') : '';
}

// Open tasks that are overdue or due within the next 7 days.
function nl_tasks(PDO $pdo, string $uid, DateTimeImmutable $today): array {
    $end = $today->modify('+' . (NL_DAYS - 1) . ' days')->format('Y-m-d');
    $st = $pdo->prepare('SELECT title, due_date, due_time FROM tasks
                         WHERE user_id = ? AND done = 0 AND due_date IS NOT NULL AND due_date <= ?
                         ORDER BY due_date, due_time IS NULL, due_time, created_at LIMIT ' . NL_MAX_ITEMS);
    $st->execute([$uid, $end]);
    $out = [];
    $t0 = $today->format('Y-m-d');
    foreach ($st->fetchAll() as $r) {
        $when = DateTimeImmutable::createFromFormat('!Y-m-d', $r['due_date']);
        $label = $when->format('D, M j') . ($r['due_time'] ? ' at ' . nl_time12($r['due_time']) : '');
        $out[] = ['title' => $r['title'], 'when' => $label, 'overdue' => $r['due_date'] < $t0];
    }
    return $out;
}

// Events that start, or are still running, during the next 7 days.
function nl_events(PDO $pdo, string $uid, DateTimeImmutable $today): array {
    $t0 = $today->format('Y-m-d');
    $end = $today->modify('+' . (NL_DAYS - 1) . ' days')->format('Y-m-d');
    $st = $pdo->prepare('SELECT title, event_date, end_date, all_day, start_time, end_time, location, urgency FROM events
                         WHERE user_id = ? AND event_date <= ? AND COALESCE(end_date, event_date) >= ?
                         ORDER BY event_date, all_day DESC, start_time, created_at LIMIT ' . NL_MAX_ITEMS);
    $st->execute([$uid, $end, $t0]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $d1 = DateTimeImmutable::createFromFormat('!Y-m-d', $r['event_date']);
        $multi = $r['end_date'] && $r['end_date'] > $r['event_date'];
        if ($multi) {
            $d2 = DateTimeImmutable::createFromFormat('!Y-m-d', $r['end_date']);
            $label = $d1->format('D, M j') . ' to ' . $d2->format('D, M j');
        } else {
            $label = $d1->format('D, M j');
        }
        if (!$r['all_day'] && $r['start_time']) {
            $label .= ', ' . nl_time12($r['start_time']) . ($r['end_time'] && !$multi ? '–' . nl_time12($r['end_time']) : '');
        } elseif (!$multi) {
            $label .= ', all day';
        }
        $out[] = ['title' => $r['title'], 'when' => $label, 'where' => (string)$r['location'], 'urgency' => (string)$r['urgency']];
    }
    return $out;
}

const NL_URGENCY = [
    'urgent' => ["Duckin' Urgent", '#DD2E44'],
    'soon'   => ['Quack in a Bit', '#E0A800'],
    'chill'  => ['No Need to Get Your Feathers Ruffled', '#78B159'],
];

// ---------- weather (Open-Meteo: free, no key) ----------
function nl_wmo(int $c): string {
    if ($c === 0) return 'Clear';
    if ($c === 1) return 'Mostly clear';
    if ($c === 2) return 'Partly cloudy';
    if ($c === 3) return 'Overcast';
    if ($c === 45 || $c === 48) return 'Foggy';
    if ($c >= 51 && $c <= 57) return 'Drizzle';
    if ($c >= 61 && $c <= 65) return $c === 61 ? 'Light rain' : 'Rain';
    if ($c === 66 || $c === 67) return 'Freezing rain';
    if ($c >= 71 && $c <= 77) return $c === 71 ? 'Light snow' : 'Snow';
    if ($c >= 80 && $c <= 82) return 'Rain showers';
    if ($c === 85 || $c === 86) return 'Snow showers';
    if ($c >= 95) return 'Thunderstorms';
    return 'Mixed';
}
function nl_is_wet(int $code, int $pop): bool {
    return $pop >= 50 || ($code >= 51 && $code <= 67) || ($code >= 71 && $code <= 99);
}

// Seven days of forecast for the configured place, or null if the service could not be reached.
function nl_weather(DateTimeImmutable $today): ?array {
    static $cache = [];
    $lat = (float)nl_cfg('weather_lat', 45.3876);     // Carleton University, Ottawa
    $lon = (float)nl_cfg('weather_lon', -75.6960);
    $key = $lat . ',' . $lon . ',' . $today->format('Y-m-d');
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $base = (string)nl_cfg('weather_url', 'https://api.open-meteo.com/v1/forecast');
    $url = $base . '?latitude=' . $lat . '&longitude=' . $lon
        . '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max'
        . '&timezone=auto&forecast_days=' . NL_DAYS;
    $body = http_get($url);
    $j = $body === null ? null : json_decode($body, true);
    $d = is_array($j) ? ($j['daily'] ?? null) : null;
    if (!is_array($d) || empty($d['time'])) {
        return $cache[$key] = null;
    }
    $days = [];
    foreach ($d['time'] as $i => $date) {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$date);
        if (!$dt) {
            continue;
        }
        $code = (int)($d['weather_code'][$i] ?? 0);
        $pop = (int)round((float)($d['precipitation_probability_max'][$i] ?? 0));
        $days[] = [
            'label' => $dt->format('D, M j'),
            'text' => nl_wmo($code),
            'hi' => (int)round((float)($d['temperature_2m_max'][$i] ?? 0)),
            'lo' => (int)round((float)($d['temperature_2m_min'][$i] ?? 0)),
            'pop' => $pop,
            'wet' => nl_is_wet($code, $pop),
        ];
    }
    return $cache[$key] = ($days ?: null);
}

// ---------- the email ----------
function nl_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nl_unsub_token(string $uid): string {
    $secret = (string)nl_cfg('newsletter_secret', (string)nl_cfg('resend_api_key', '') . (string)nl_cfg('db_pass', ''));
    return hash_hmac('sha256', 'unsubscribe|' . $uid, $secret);
}
function nl_unsub_url(string $uid): string {
    $site = rtrim((string)nl_cfg('site_url', 'https://jashducksinarow.com'), '/');
    return $site . '/api/unsubscribe.php?u=' . rawurlencode($uid) . '&t=' . nl_unsub_token($uid);
}

// Returns [subject, html, text].
function nl_render(string $name, array $tasks, array $events, ?array $weather, string $unsubUrl): array {
    $place = (string)nl_cfg('weather_place', 'Ottawa');
    $site = rtrim((string)nl_cfg('site_url', 'https://jashducksinarow.com'), '/');
    $address = (string)nl_cfg('newsletter_address', '');
    $subject = 'Your week ahead from Ducks In A Row 🦆';

    // plain-text lines and html list items for each section
    $tLines = $tHtml = [];
    foreach ($tasks as $t) {
        $pre = $t['overdue'] ? 'Overdue: ' : '';
        $tLines[] = $pre . $t['title'] . ' (due ' . $t['when'] . ')';
        $tHtml[] = ($t['overdue'] ? '<strong style="color:#B3392B">Overdue:</strong> ' : '') . nl_h($t['title'])
            . ' <span style="color:#6B6F73">(due ' . nl_h($t['when']) . ')</span>';
    }
    if (!$tLines) {
        $tLines[] = 'Nothing due this week. Enjoy the quiet!';
        $tHtml[] = 'Nothing due this week. Enjoy the quiet!';
    }
    $eLines = $eHtml = [];
    foreach ($events as $e) {
        [$uName, $uColor] = NL_URGENCY[$e['urgency']] ?? NL_URGENCY['chill'];
        $line = $e['title'] . ' (' . $e['when'] . ($e['where'] !== '' ? ', ' . $e['where'] : '') . ') [' . $uName . ']';
        $eLines[] = $line;
        $eHtml[] = nl_h($e['title']) . ' <span style="color:#6B6F73">(' . nl_h($e['when']) . ($e['where'] !== '' ? ', ' . nl_h($e['where']) : '') . ')</span>'
            . ' <span style="white-space:nowrap;font-size:12px;color:' . $uColor . '">&#9679; ' . nl_h($uName) . '</span>';
    }
    if (!$eLines) {
        $eLines[] = 'No events on the calendar this week.';
        $eHtml[] = 'No events on the calendar this week.';
    }
    $wLines = $wHtml = [];
    if ($weather) {
        foreach ($weather as $w) {
            $line = $w['label'] . ': ' . $w['text'] . ', ' . $w['hi'] . '°C / ' . $w['lo'] . '°C' . ($w['pop'] > 0 ? ', ' . $w['pop'] . '% chance of precipitation' : '');
            $wLines[] = $line;
            $wHtml[] = '<strong>' . nl_h($w['label']) . ':</strong> ' . nl_h($w['text']) . ', ' . $w['hi'] . '°C / ' . $w['lo'] . '°C'
                . ($w['pop'] > 0 ? ' <span style="color:#6B6F73">&middot; ' . $w['pop'] . '% chance of precipitation</span>' : '');
        }
        $wet = array_values(array_map(fn($w) => substr($w['label'], 0, 3), array_filter($weather, fn($w) => $w['wet'])));
        if ($wet) {
            $wLines[] = 'Pack an umbrella for: ' . implode(', ', $wet) . '.';
            $wHtml[] = '<strong>Pack an umbrella for:</strong> ' . nl_h(implode(', ', $wet)) . '.';
        }
        array_unshift($wLines, "($place forecast)");
    } else {
        $wLines[] = "We couldn't fetch the forecast this time. Check your weather app before you head out!";
        $wHtml[] = "We couldn't fetch the forecast this time. Check your weather app before you head out!";
    }

    $lead = "Hey there $name! How's your weekend so far?";
    $intro = "To get ahead of your busy week, here's a quick rundown of what's on your calendar:";
    $weatherHead = "Here's a look at the weather this week (so you're not sitting like a lame duck when it rains on you):";
    $signoff = 'Have a great week ahead,';
    $team = '— The Jash Ducks In A Row team';

    $text = $lead . "\n\n" . $intro . "\n\nYour upcoming tasks:\n"
        . implode("\n", array_map(fn($l) => '* ' . $l, $tLines))
        . "\n\nYour upcoming events:\n" . implode("\n", array_map(fn($l) => '* ' . $l, $eLines))
        . "\n\n" . $weatherHead . "\n" . implode("\n", array_map(fn($l) => (str_starts_with($l, '(') ? '' : '* ') . $l, $wLines))
        . "\n\n" . $signoff . "\n" . $team
        . "\n\n--\nYou get this email every Sunday because you have a Ducks In A Row account. Unsubscribe: $unsubUrl"
        . ($address !== '' ? "\n$address" : '') . "\n";

    $li = function (array $items): string {
        return '<ul style="margin:8px 0 0;padding-left:22px">' . implode('', array_map(fn($i) => '<li style="margin:0 0 7px">' . $i . '</li>', $items)) . '</ul>';
    };
    $h = fn(string $s) => '<h2 style="margin:26px 0 0;font:700 19px Georgia,\'Times New Roman\',serif;color:#262F35">' . $s . '</h2>';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . nl_h($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#262F35">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#262F35"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
        . '<tr><td style="padding:0 6px 14px;font:700 24px Georgia,\'Times New Roman\',serif;color:#E9CD52">Ducks In A Row</td></tr>'
        . '<tr><td style="background:#FEF5DB;border-radius:18px;padding:30px 28px;font:16px/1.5 Arial,Helvetica,sans-serif;color:#262F35">'
        . '<p style="margin:0 0 14px;font:700 24px/1.25 Georgia,\'Times New Roman\',serif">' . nl_h($lead) . '</p>'
        . '<p style="margin:0">' . nl_h($intro) . '</p>'
        . $h('Your upcoming tasks:') . $li($tHtml)
        . $h('Your upcoming events:') . $li($eHtml)
        . $h(nl_h($weatherHead)) . ($weather ? '<p style="margin:6px 0 0;font-size:13px;color:#6B6F73">' . nl_h($place) . ' forecast</p>' : '') . $li($wHtml)
        . '<p style="margin:28px 0 0">' . nl_h($signoff) . '<br>' . nl_h($team) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 8px 0;font:12px/1.5 Arial,Helvetica,sans-serif;color:#9AA5AB;text-align:center">'
        . 'You get this email every Sunday because you have a <a href="' . nl_h($site) . '" style="color:#E9CD52">Ducks In A Row</a> account. '
        . '<a href="' . nl_h($unsubUrl) . '" style="color:#E9CD52">Unsubscribe</a>'
        . ($address !== '' ? '<br>' . nl_h($address) : '') . '</td></tr>'
        . '</table></td></tr></table></body></html>';
    return [$subject, $html, $text];
}

// ---------- Clerk (to find the person's email) and Resend (to send) ----------
function nl_curl(string $method, string $url, array $headers, ?string $body): array {
    if (!function_exists('curl_init')) {
        return [0, 'PHP curl is not available'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $res === false ? $err : (string)$res];
}

// The person's primary email, from Clerk's backend API. null = could not find one.
function nl_clerk_email(string $uid): ?string {
    $secret = (string)nl_cfg('clerk_secret_key', '');
    if ($secret === '') {
        return null;
    }
    $base = rtrim((string)nl_cfg('clerk_api_url', 'https://api.clerk.com/v1'), '/');
    [$code, $body] = nl_curl('GET', $base . '/users/' . rawurlencode($uid), ['Authorization: Bearer ' . $secret, 'Accept: application/json'], null);
    if ($code !== 200) {
        return null;
    }
    $u = json_decode($body, true);
    if (!is_array($u)) {
        return null;
    }
    $primary = $u['primary_email_address_id'] ?? null;
    $fallback = null;
    foreach (($u['email_addresses'] ?? []) as $e) {
        $addr = $e['email_address'] ?? null;
        if (!is_string($addr) || !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if (($e['id'] ?? null) === $primary) {
            return $addr;
        }
        $fallback = $fallback ?? $addr;
    }
    return $fallback;
}

// Sends one email through Resend. Returns [ok, detail]. The idempotency key stops Resend from sending a
// duplicate if we retry the same person and week.
function nl_resend_send(string $to, string $subject, string $html, string $text, string $unsubUrl, string $idem): array {
    $key = (string)nl_cfg('resend_api_key', '');
    if ($key === '') {
        return [false, 'resend_api_key is not set in api/config.php'];
    }
    $from = (string)nl_cfg('newsletter_from', 'Ducks In A Row <hello@jashducksinarow.com>');
    $payload = [
        'from' => $from, 'to' => [$to], 'subject' => $subject, 'html' => $html, 'text' => $text,
        'headers' => ['List-Unsubscribe' => '<' . $unsubUrl . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
    ];
    $reply = (string)nl_cfg('newsletter_reply_to', '');
    if ($reply !== '') {
        $payload['reply_to'] = $reply;
    }
    $url = (string)nl_cfg('resend_api_url', 'https://api.resend.com/emails');
    [$code, $body] = nl_curl('POST', $url, [
        'Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Idempotency-Key: ' . $idem,
        'User-Agent: ducks-in-a-row-newsletter/1.0',
    ], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($code >= 200 && $code < 300) {
        $j = json_decode($body, true);
        return [true, is_array($j) && isset($j['id']) ? (string)$j['id'] : 'sent'];
    }
    $j = json_decode($body, true);
    $msg = is_array($j) ? (string)($j['message'] ?? $j['error'] ?? '') : '';
    return [false, 'Resend HTTP ' . $code . ($msg !== '' ? ': ' . $msg : ($code === 0 ? ': ' . $body : ''))];
}

// ---------- the run ----------
// Claims this person's slot for the week. true = we should send now; false = already sent / being sent / gave up.
function nl_claim(PDO $pdo, string $uid, string $week): bool {
    $pdo->prepare('INSERT IGNORE INTO newsletter_sends (user_id, week_key, status, attempts) VALUES (?,?,?,0)')->execute([$uid, $week, 'sending']);
    $st = $pdo->prepare("UPDATE newsletter_sends SET status = 'sending', attempts = attempts + 1
                         WHERE user_id = ? AND week_key = ? AND attempts < ?
                           AND (status = 'failed' OR (status = 'sending' AND updated_at < (NOW() - INTERVAL 30 MINUTE)) OR (status = 'sending' AND attempts = 0))");
    $st->execute([$uid, $week, NL_MAX_ATTEMPTS]);
    return $st->rowCount() === 1;
}
function nl_mark(PDO $pdo, string $uid, string $week, string $status, string $detail): void {
    $pdo->prepare('UPDATE newsletter_sends SET status = ?, detail = ? WHERE user_id = ? AND week_key = ?')
        ->execute([$status, mb_substr($detail, 0, 250), $uid, $week]);
}

// $o: now (DateTimeImmutable, UTC), dry (build but do not send), force (ignore day/time and the sent log),
// user (only this Clerk id), to (send to this address instead, for tests). Returns log lines.
function nl_run(PDO $pdo, array $o): array {
    $now = $o['now'] ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $dry = !empty($o['dry']);
    $force = !empty($o['force']);
    $log = [];

    $sql = 'SELECT user_id, full_name, timezone FROM profiles WHERE onboarded = 1 AND newsletter_opt_in = 1';
    $args = [];
    if (!empty($o['user'])) {
        $sql .= ' AND user_id = ?';
        $args[] = $o['user'];
    }
    $st = $pdo->prepare($sql . ' ORDER BY created_at LIMIT 2000');
    $st->execute($args);
    $people = $st->fetchAll();
    $log[] = count($people) . ' subscribed profile(s) checked at ' . $now->format('Y-m-d H:i') . ' UTC.';

    $sent = 0;
    foreach ($people as $p) {
        $uid = $p['user_id'];
        $tz = nl_tz($p['timezone']);
        $local = $now->setTimezone($tz);
        $week = nl_is_due($now, $tz);
        if ($week === null) {
            if (!$force && !$dry) {
                continue;                   // dry runs and forced test sends ignore the day and time
            }
            $week = $local->modify('sunday this week')->format('Y-m-d');
            if ($local->format('w') === '0') {
                $week = $local->format('Y-m-d');
            }
        }
        $today = $local->setTime(0, 0, 0);
        $to = $o['to'] ?? null;

        if (!$dry && !$force && !nl_claim($pdo, $uid, $week)) {
            continue;                       // already sent (or being sent) this week
        }
        if ($force && !$dry) {
            $pdo->prepare('INSERT IGNORE INTO newsletter_sends (user_id, week_key, status, attempts) VALUES (?,?,?,0)')->execute([$uid, $week, 'sending']);
        }
        $fail = function (string $why) use ($pdo, $uid, $week, $dry, &$log) {
            $log[] = "FAILED $uid: $why";
            if (!$dry) {
                nl_mark($pdo, $uid, $week, 'failed', $why);
            }
        };
        try {
            if ($to === null) {
                $to = nl_clerk_email($uid);
                if ($to === null) {
                    $fail(nl_cfg('clerk_secret_key') ? 'no email address found in Clerk' : 'clerk_secret_key is not set in api/config.php');
                    continue;
                }
            }
            $tasks = nl_tasks($pdo, $uid, $today);
            $events = nl_events($pdo, $uid, $today);
            $weather = nl_weather($today);
            [$subject, $html, $text] = nl_render(nl_first_name($p['full_name']), $tasks, $events, $weather, nl_unsub_url($uid));
            if ($dry) {
                $dir = rtrim((string)nl_cfg('cache_dir', __DIR__ . '/cache'), '/');
                @mkdir($dir, 0755, true);
                $f = $dir . '/newsletter-preview-' . preg_replace('/[^A-Za-z0-9_-]/', '', $uid) . '.html';
                file_put_contents($f, $html);
                $log[] = "PREVIEW $uid -> would email $to; saved $f";
                $log[] = $text;
                continue;
            }
            [$ok, $detail] = nl_resend_send($to, $subject, $html, $text, nl_unsub_url($uid), 'weekly-' . $uid . '-' . $week . ($force ? '-' . $now->format('His') : ''));
            if ($ok) {
                nl_mark($pdo, $uid, $week, 'sent', $detail);
                $log[] = "SENT $uid ($week) id=$detail";
                $sent++;
            } else {
                $fail($detail);
            }
        } catch (Throwable $e) {
            error_log('[ducks-newsletter] ' . $e);
            $fail('unexpected error: ' . $e->getMessage());
        }
        usleep(600000);                     // Resend allows 2 requests a second
    }
    $log[] = "Done. $sent sent.";
    return $log;
}
