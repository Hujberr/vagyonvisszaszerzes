<?php
/*
 * Push-értesítések közös függvényei (közvetlenül nem hívható, .htaccess tiltja).
 * Tartalom nélküli (payload-less) Web Push + VAPID aláírás, külső könyvtár nélkül:
 * a böngésző az értesítés szövegét a push-subscribe.php?action=latest címről kéri le.
 */
declare(strict_types=1);

const PUSH_ALLOWED_HOSTS = ['googleapis.com', 'mozilla.com', 'mozaws.net', 'push.apple.com', 'notify.windows.com'];

function push_cfg(): array { return require __DIR__ . '/counter-config.php'; }

function push_db(array $cfg): PDO {
    $pdo = new PDO('sqlite:' . $cfg['push_db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS subs (endpoint TEXT PRIMARY KEY, lang TEXT NOT NULL DEFAULT "hu", created TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS state (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sub_log (day TEXT NOT NULL, kind TEXT NOT NULL, n INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (day, kind))');
    return $pdo;
}

function push_state_get(PDO $pdo, string $k): ?string {
    $s = $pdo->prepare('SELECT v FROM state WHERE k = ?'); $s->execute([$k]);
    $v = $s->fetchColumn(); return $v === false ? null : (string)$v;
}
function push_state_set(PDO $pdo, string $k, string $v): void {
    $pdo->prepare('INSERT INTO state (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

/* Napi feliratkozási napló: new | unsub | gone (a böngésző megszüntette). */
function push_log(PDO $pdo, string $kind, int $n = 1): void {
    if ($n < 1) return;
    $day = (new DateTime('now', new DateTimeZone('Europe/Budapest')))->format('Y-m-d');
    $pdo->prepare('INSERT INTO sub_log (day, kind, n) VALUES (?, ?, ?) ON CONFLICT(day, kind) DO UPDATE SET n = n + excluded.n')->execute([$day, $kind, $n]);
}

function push_valid_endpoint(string $ep): bool {
    if (strlen($ep) > 800 || !str_starts_with($ep, 'https://')) return false;
    $host = strtolower((string)parse_url($ep, PHP_URL_HOST));
    foreach (PUSH_ALLOWED_HOSTS as $h) { if ($host === $h || str_ends_with($host, '.' . $h)) return true; }
    return false;
}

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/* ECDSA DER-aláírás → 64 bájtos r||s (JWT ES256). */
function push_der2raw(string $der): string {
    $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $rl = ord($der[$o + 1]); $r = substr($der, $o + 2, $rl); $o += 2 + $rl;
    $sl = ord($der[$o + 1]); $s = substr($der, $o + 2, $sl);
    $fix = fn($x) => str_pad(substr(ltrim($x, "\0"), -32), 32, "\0", STR_PAD_LEFT);
    return $fix($r) . $fix($s);
}

function push_jwt(string $aud, array $cfg): string {
    static $cache = [];
    if (isset($cache[$aud])) return $cache[$aud];
    $h = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $p = b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $cfg['vapid_subject']]));
    $key = openssl_pkey_get_private($cfg['vapid_private_pem']);
    if (!$key || !openssl_sign("$h.$p", $der, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('vapid_sign_failed');
    return $cache[$aud] = "$h.$p." . b64u(push_der2raw($der));
}

/* Egy értesítés küldése; visszaad: HTTP-kód (0 = hálózati hiba). */
function push_send_one(string $endpoint, array $cfg): int {
    $u = parse_url($endpoint);
    $jwt = push_jwt($u['scheme'] . '://' . $u['host'], $cfg);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['TTL: 86400', 'Urgency: normal', 'Content-Length: 0',
            'Authorization: vapid t=' . $jwt . ', k=' . $cfg['vapid_public']],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/* Küldés a megadott (vagy az összes) feliratkozónak; a megszűnt feliratkozásokat törli. */
function push_send(PDO $pdo, array $cfg, ?array $endpoints = null): array {
    $list = $endpoints ?? $pdo->query('SELECT endpoint FROM subs')->fetchAll(PDO::FETCH_COLUMN);
    $ok = 0; $gone = 0; $fail = 0;
    $del = $pdo->prepare('DELETE FROM subs WHERE endpoint = ?');
    foreach ($list as $ep) {
        $c = push_send_one($ep, $cfg);
        if ($c >= 200 && $c < 300) $ok++;
        elseif ($c === 404 || $c === 410) { $del->execute([$ep]); $gone++; }
        else $fail++;
    }
    push_log($pdo, 'gone', $gone);
    return ['sent' => $ok, 'removed' => $gone, 'failed' => $fail];
}
