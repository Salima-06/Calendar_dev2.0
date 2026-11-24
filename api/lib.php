<?php
// Helpers for the Ducks In A Row API: responses, database, Clerk sign-in check, validation.

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
    }
    return $c;
}

// ---------- responses ----------
function out($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $message): void {
    out(['error' => $message], $status);
}

// ---------- database ----------
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg();
        if ($c['db_host'] === '' || $c['db_name'] === '' || $c['db_user'] === '') {
            fail(500, 'The database is not set up yet. Fill in the database details in api/config.php.');
        }
        $pdo = new PDO(
            'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4',
            $c['db_user'],
            $c['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

// ---------- Clerk sign-in check ----------
function b64url_decode(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $d = base64_decode($s, true);
    return $d === false ? '' : $d;
}

function clerk_host(): string {
    $key = cfg()['clerk_publishable_key'] ?? '';
    $parts = explode('_', $key);
    if (count($parts) < 3) {
        fail(500, 'Clerk is not set up yet. Check clerk_publishable_key in api/config.php.');
    }
    $host = rtrim(b64url_decode($parts[2]), '$');
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
        fail(500, 'The Clerk key in api/config.php is not valid.');
    }
    return $host;
}

function http_get(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code === 200) {
            return $body;
        }
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false) {
            return $body;
        }
    }
    return null;
}

function cache_path(): string {
    $dir = cfg()['cache_dir'] ?? (__DIR__ . '/cache');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = sys_get_temp_dir();
    }
    return rtrim($dir, '/') . '/clerk-jwks-' . md5(clerk_host()) . '.json';
}

// Returns Clerk's public keys, cached for an hour. $force re-downloads.
function load_jwks(bool $force = false): array {
    $path = cache_path();
    if (!$force && is_file($path) && time() - filemtime($path) < 3600) {
        $j = json_decode((string)file_get_contents($path), true);
        if (is_array($j) && !empty($j['keys'])) {
            return $j['keys'];
        }
    }
    $url = cfg()['jwks_url'] ?? ('https://' . clerk_host() . '/.well-known/jwks.json');
    $body = http_get($url);
    if ($body !== null) {
        $j = json_decode($body, true);
        if (is_array($j) && !empty($j['keys'])) {
            @file_put_contents($path, $body);
            return $j['keys'];
        }
    }
    // Could not download: fall back to an older cached copy if there is one.
    if (is_file($path)) {
        $j = json_decode((string)file_get_contents($path), true);
        if (is_array($j) && !empty($j['keys'])) {
            return $j['keys'];
        }
    }
    fail(503, 'Could not reach Clerk to check your sign-in. Please try again.');
}

function find_jwk(string $kid): ?array {
    foreach ([false, true] as $force) {
        // Only force a re-download if the key id is unknown AND the cache is not brand new.
        if ($force && is_file(cache_path()) && time() - filemtime(cache_path()) < 60) {
            break;
        }
        foreach (load_jwks($force) as $k) {
            if (($k['kid'] ?? '') === $kid && ($k['kty'] ?? '') === 'RSA') {
                return $k;
            }
        }
    }
    return null;
}

function der_len(int $l): string {
    if ($l < 128) {
        return chr($l);
    }
    $b = ltrim(pack('N', $l), "\0");
    return chr(0x80 | strlen($b)) . $b;
}
function der_int(string $b): string {
    if ($b === '' || ord($b[0]) > 127) {
        $b = "\0" . $b;
    }
    return "\x02" . der_len(strlen($b)) . $b;
}
function der_seq(string $b): string {
    return "\x30" . der_len(strlen($b)) . $b;
}

function jwk_to_pem(array $jwk): string {
    $n = b64url_decode($jwk['n'] ?? '');
    $e = b64url_decode($jwk['e'] ?? '');
    $rsa = der_seq(der_int($n) . der_int($e));
    $bit = "\x03" . der_len(strlen($rsa) + 1) . "\0" . $rsa;
    $algo = hex2bin('300d06092a864886f70d0101010500');
    $spki = der_seq($algo . $bit);
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function bearer_token(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $h = $v;
            }
        }
    }
    if (stripos($h, 'Bearer ') === 0) {
        return trim(substr($h, 7));
    }
    // Some hosts strip the Authorization header, so the site also sends a copy here.
    return trim($_SERVER['HTTP_X_CLERK_TOKEN'] ?? '');
}

// Verifies the signed-in user's Clerk token and returns their Clerk user id.
function current_user_id(): string {
    $token = bearer_token();
    if ($token === '') {
        fail(401, 'Please sign in.');
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        fail(401, 'Invalid sign-in.');
    }
    [$h, $p, $s] = $parts;
    $header = json_decode(b64url_decode($h), true);
    $payload = json_decode(b64url_decode($p), true);
    if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
        fail(401, 'Invalid sign-in.');
    }
    $jwk = find_jwk((string)$header['kid']);
    if ($jwk === null) {
        fail(401, 'Sign-in could not be verified.');
    }
    $ok = openssl_verify($h . '.' . $p, b64url_decode($s), jwk_to_pem($jwk), OPENSSL_ALGO_SHA256);
    if ($ok !== 1) {
        fail(401, 'Invalid sign-in.');
    }
    $now = time();
    $leeway = 15;
    if (!isset($payload['exp']) || $payload['exp'] < $now - $leeway) {
        fail(401, 'Your sign-in expired. Please refresh the page.');
    }
    if (isset($payload['nbf']) && $payload['nbf'] > $now + $leeway) {
        fail(401, 'Invalid sign-in.');
    }
    if (($payload['iss'] ?? '') !== 'https://' . clerk_host()) {
        fail(401, 'Invalid sign-in.');
    }
    $allowed = cfg()['allowed_origins'] ?? [];
    if ($allowed && isset($payload['azp']) && !in_array($payload['azp'], $allowed, true)) {
        fail(401, 'This website is not allowed to use the API.');
    }
    $sub = $payload['sub'] ?? '';
    if (!is_string($sub) || $sub === '' || strlen($sub) > 64) {
        fail(401, 'Invalid sign-in.');
    }
    return $sub;
}

// ---------- validation ----------
function body(): array {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        fail(413, 'Request is too large.');
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        fail(400, 'Invalid request.');
    }
    return $j;
}

function v_str($v, int $max, bool $required = true, string $label = 'This field'): ?string {
    $v = is_string($v) ? trim($v) : '';
    if ($v === '') {
        if ($required) {
            fail(422, "$label is required.");
        }
        return null;
    }
    if (mb_strlen($v) > $max) {
        fail(422, "$label is too long (max $max characters).");
    }
    return $v;
}

function v_date($v, bool $required, string $label = 'Date'): ?string {
    if ($v === null || $v === '') {
        if ($required) {
            fail(422, "$label is required.");
        }
        return null;
    }
    $d = is_string($v) ? DateTime::createFromFormat('!Y-m-d', $v) : false;
    if (!$d || $d->format('Y-m-d') !== $v) {
        fail(422, "$label is not a valid date.");
    }
    return $v;
}

function v_time($v, string $label = 'Time'): ?string {
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v)) {
        fail(422, "$label is not a valid time.");
    }
    return substr($v, 0, 5) . ':00';
}

function v_id($v): string {
    if (!is_string($v) || !preg_match('/^[0-9a-f-]{36}$/', $v)) {
        fail(422, 'Invalid item.');
    }
    return $v;
}

function hhmm($t): ?string {
    return $t === null ? null : substr($t, 0, 5);
}
