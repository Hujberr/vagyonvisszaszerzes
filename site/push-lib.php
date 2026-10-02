<?php
/*
 * Push-értesítések közös függvényei (közvetlenül nem hívható, .htaccess tiltja).
 * Web Push + VAPID aláírás, külső könyvtár nélkül. Ha a feliratkozás kulcsai (p256dh, auth) ismertek,
 * az értesítés szövege titkosítva (RFC 8291, aes128gcm) magában a push-üzenetben érkezik; egyébként
 * (régi feliratkozás vagy hiba esetén) tartalom nélküli push megy, és a böngésző a szöveget a
 * push-subscribe.php?action=latest címről kéri le.
 */
declare(strict_types=1);

const PUSH_ALLOWED_HOSTS = ['googleapis.com', 'mozilla.com', 'mozaws.net', 'push.apple.com', 'notify.windows.com'];

function push_cfg(): array { return require __DIR__ . '/counter-config.php'; }

function push_db(array $cfg): PDO {
    $pdo = new PDO('sqlite:' . $cfg['push_db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS subs (endpoint TEXT PRIMARY KEY, lang TEXT NOT NULL DEFAULT "hu", created TEXT NOT NULL)');
    try {
        $have = array_column($pdo->query('PRAGMA table_info(subs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('p256dh', $have, true)) $pdo->exec('ALTER TABLE subs ADD COLUMN p256dh TEXT');
        if (!in_array('auth', $have, true)) $pdo->exec('ALTER TABLE subs ADD COLUMN auth TEXT');
    } catch (Throwable $e) { /* a régi működés változatlanul megmarad */ }
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
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

/* Feliratkozási kulcsok ellenőrzése: p256dh = 65 bájtos tömörítetlen P-256 pont, auth = 16 bájt. */
function push_valid_keys(?string $p256dh, ?string $auth): bool {
    if (!is_string($p256dh) || !is_string($auth) || strlen($p256dh) > 100 || strlen($auth) > 40) return false;
    $k = b64u_dec($p256dh); $a = b64u_dec($auth);
    return strlen($k) === 65 && $k[0] === "\x04" && strlen($a) === 16;
}

/* Üzenet titkosítása Web Push-hoz (RFC 8291, aes128gcm, egyetlen rekord). Hiba esetén null. */
function push_encrypt(string $plain, string $p256dhB64u, string $authB64u): ?string {
    try {
        $uaPub = b64u_dec($p256dhB64u); $auth = b64u_dec($authB64u);
        if (strlen($uaPub) !== 65 || strlen($auth) !== 16 || strlen($plain) > 3800) return null;
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $uaPub;
        $peer = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n");
        $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$peer || !$local) return null;
        $ec = openssl_pkey_get_details($local)['ec'];
        $asPub = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $secret = openssl_pkey_derive($peer, $local, 32);
        if ($secret === false) return null;
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPub . $asPub, $auth);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $ct = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) return null;
        return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
    } catch (Throwable $e) { return null; }
}

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

/* Egy értesítés küldése (törzs nélkül vagy titkosított törzzsel); visszaad: HTTP-kód (0 = hálózati hiba). */
function push_send_one(string $endpoint, array $cfg, ?string $body = null): int {
    $u = parse_url($endpoint);
    $jwt = push_jwt($u['scheme'] . '://' . $u['host'], $cfg);
    $body = $body ?? '';
    $hdr = ['TTL: 86400', 'Urgency: normal', 'Content-Length: ' . strlen($body),
        'Authorization: vapid t=' . $jwt . ', k=' . $cfg['vapid_public']];
    if ($body !== '') { $hdr[] = 'Content-Encoding: aes128gcm'; $hdr[] = 'Content-Type: application/octet-stream'; }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $hdr]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/* Küldés a megadott (vagy az összes) feliratkozónak; a megszűnt feliratkozásokat törli.
   Az összes feliratkozónak küldve (nincs megadott lista) az utolsó üzenet szövege a push-üzenetbe kerül,
   ha a feliratkozás kulcsai ismertek; hiba esetén a tartalom nélküli küldés a tartalék. */
function push_send(PDO $pdo, array $cfg, ?array $endpoints = null): array {
    $usePayload = $endpoints === null;
    $latest = $usePayload ? json_decode(push_state_get($pdo, 'latest') ?? 'null', true) : null;
    if ($endpoints === null) {
        $rows = $pdo->query('SELECT endpoint, lang, p256dh, auth FROM subs')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = array_map(fn($e) => ['endpoint' => $e, 'lang' => 'hu', 'p256dh' => null, 'auth' => null], $endpoints);
    }
    $ok = 0; $gone = 0; $fail = 0; $withText = 0;
    $del = $pdo->prepare('DELETE FROM subs WHERE endpoint = ?');
    foreach ($rows as $r) {
        $ep = $r['endpoint']; $body = null;
        $lang = ($r['lang'] ?? 'hu') === 'en' ? 'en' : 'hu';
        if (is_array($latest) && isset($latest[$lang]) && push_valid_keys($r['p256dh'] ?? null, $r['auth'] ?? null)) {
            $body = push_encrypt(json_encode($latest[$lang], JSON_UNESCAPED_UNICODE), $r['p256dh'], $r['auth']);
        }
        $c = push_send_one($ep, $cfg, $body);
        if ($body !== null && !($c >= 200 && $c < 300) && $c !== 404 && $c !== 410) $c = push_send_one($ep, $cfg, null);
        elseif ($body !== null && $c >= 200 && $c < 300) $withText++;
        if ($c >= 200 && $c < 300) $ok++;
        elseif ($c === 404 || $c === 410) { $del->execute([$ep]); $gone++; }
        else $fail++;
    }
    push_log($pdo, 'gone', $gone);
    return ['sent' => $ok, 'removed' => $gone, 'failed' => $fail, 'with_text' => $withText];
}
