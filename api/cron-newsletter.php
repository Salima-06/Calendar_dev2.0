<?php
// Scheduled job: sends the weekly "Your week ahead" email. Set it to run every 5 minutes (see SETUP.md).
// It only sends to people whose local time is Sunday 3:33 pm or later (for the next few hours), once per week each.
//
//   Command line:  php cron-newsletter.php                      (what the cron job runs)
//                  php cron-newsletter.php --dry --user=user_abc (preview: builds the email, sends nothing)
//                  php cron-newsletter.php --force --to=you@example.com --user=user_abc   (send a test right now)
//   Web (if your host only offers URL cron jobs):
//                  https://jashducksinarow.com/api/cron-newsletter.php?key=YOUR_CRON_KEY
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/newsletter.php';

$cli = PHP_SAPI === 'cli';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if ($cli) {
    $opt = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
            $opt[$m[1]] = $m[2] ?? '1';
        }
    }
} else {
    $want = (string)(cfg()['cron_key'] ?? '');
    $got = (string)($_GET['key'] ?? '');
    if ($want === '' || strlen($want) < 16 || !hash_equals($want, $got)) {
        http_response_code(403);
        echo "Not allowed.\n";
        exit;
    }
    $opt = $_GET;
}

try {
    $pdo = db();
    ensure_schema($pdo);
    ensure_newsletter_schema($pdo);

    // One run at a time (cron jobs can overlap if Resend is slow).
    $dir = cfg()['cache_dir'] ?? (__DIR__ . '/cache');
    @mkdir($dir, 0755, true);
    $lock = @fopen(rtrim($dir, '/\\') . '/newsletter.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        echo "Another run is still going. Skipping.\n";
        exit;
    }

    $o = ['dry' => !empty($opt['dry']), 'force' => !empty($opt['force'])];
    if (!empty($opt['user'])) {
        $o['user'] = (string)$opt['user'];
    }
    if (!empty($opt['to'])) {
        if (!filter_var($opt['to'], FILTER_VALIDATE_EMAIL)) {
            echo "--to is not a valid email address.\n";
            exit(1);
        }
        $o['to'] = (string)$opt['to'];
        if (empty($o['user'])) {
            echo "--to needs --user=<Clerk user id> so a test goes to one person only.\n";
            exit(1);
        }
    }
    if (!empty($opt['now'])) {                     // for testing: pretend it is this moment
        $o['now'] = new DateTimeImmutable((string)$opt['now'], new DateTimeZone('UTC'));
    }
    if (($o['force'] || $o['dry']) && empty($o['user']) && !$cli) {
        echo "Use --user=<Clerk user id> with dry/force.\n";
        exit(1);
    }
    echo implode("\n", nl_run($pdo, $o)) . "\n";
} catch (Throwable $e) {
    error_log('[ducks-newsletter] ' . $e);
    http_response_code(500);
    echo "Something went wrong. See the PHP error log.\n";
    exit(1);
}
