<?php
// The "Unsubscribe" link in the newsletter. Opening the link shows a button; pressing it (or a mail app's
// one-click unsubscribe) switches the weekly email off for that person. Pressing "Resubscribe" turns it back on.
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/newsletter.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function page(string $title, string $body, int $status = 200): void {
    http_response_code($status);
    $site = rtrim((string)nl_cfg('site_url', 'https://jashducksinarow.com'), '/');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . nl_h($title) . ' | Ducks In A Row</title></head>'
        . '<body style="margin:0;background:#262F35;color:#FEF5DB;font:18px/1.5 Georgia,serif;display:grid;place-items:center;min-height:100vh;padding:20px">'
        . '<main style="max-width:460px;text-align:center"><h1 style="font-size:34px;margin:0 0 12px;color:#E9CD52">' . nl_h($title) . '</h1>' . $body
        . '<p style="margin-top:28px;font-size:15px"><a style="color:#E9CD52" href="' . nl_h($site) . '">Back to Ducks In A Row</a></p></main></body></html>';
    exit;
}
function button(string $label, string $action, string $u, string $t): string {
    return '<form method="post" action="?u=' . nl_h(rawurlencode($u)) . '&amp;t=' . nl_h($t) . '"><input type="hidden" name="do" value="' . nl_h($action) . '">'
        . '<button style="margin-top:10px;padding:14px 26px;border:0;border-radius:999px;background:#E9CD52;color:#262F35;font:700 16px Arial,sans-serif;cursor:pointer">'
        . nl_h($label) . '</button></form>';
}

$u = (string)($_GET['u'] ?? '');
$t = (string)($_GET['t'] ?? '');
if ($u === '' || strlen($u) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $u) || !hash_equals(nl_unsub_token($u), $t)) {
    page('That link is not valid', '<p>Please use the unsubscribe link from the newest email, or sign in and change it from your account.</p>', 400);
}

try {
    $pdo = db();
    ensure_newsletter_schema($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        // Mail apps send "List-Unsubscribe=One-Click"; the page's own buttons send do=...
        $do = (string)($_POST['do'] ?? '');
        $on = $do === 'resubscribe' ? 1 : 0;
        $pdo->prepare('UPDATE profiles SET newsletter_opt_in = ? WHERE user_id = ?')->execute([$on, $u]);
        if ($do === '' && isset($_POST['List-Unsubscribe'])) {
            http_response_code(200);
            echo 'OK';
            exit;
        }
        if ($on) {
            page('Welcome back!', '<p>You will get the Sunday week-ahead email again.</p>' . button('Unsubscribe', 'unsubscribe', $u, $t));
        }
        page('You are unsubscribed', '<p>No more Sunday emails. We will miss you at 3:33 pm!</p>' . button('Resubscribe', 'resubscribe', $u, $t));
    }
    page('Unsubscribe from the Sunday email?', '<p>You will stop getting the weekly week-ahead email from Ducks In A Row.</p>' . button('Yes, unsubscribe', 'unsubscribe', $u, $t));
} catch (Throwable $e) {
    error_log('[ducks-newsletter] ' . $e);
    page('Something went wrong', '<p>Please try again in a moment.</p>', 500);
}
