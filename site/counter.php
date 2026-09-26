<?php
/*
 * Látogatószámláló — sütit nem használ, IP-címet nem tárol.
 * A napi egyedi látogatót egy naponta változó, vissza nem fejthető kód azonosítja
 * (HMAC: titkos kulcs + dátum + IP + böngészőazonosító).
 * Válasz: {"total": <összes napi egyedi látogatás>}
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg = require __DIR__ . '/counter-config.php';

function db(array $cfg): PDO {
    $pdo = new PDO('sqlite:' . $cfg['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS visits (
        day TEXT NOT NULL, host TEXT NOT NULL, from_host TEXT NOT NULL DEFAULT "",
        vhash TEXT NOT NULL, hits INTEGER NOT NULL DEFAULT 1,
        PRIMARY KEY (day, host, vhash))');
    return $pdo;
}

function clean_host(string $h): string {
    $h = strtolower(trim(preg_replace('/:\d+$/', '', $h)));
    $h = preg_replace('/^www\./', '', $h);
    return preg_match('/^[a-z0-9.-]{1,100}$/', $h) ? $h : '';
}

try {
    $pdo = db($cfg);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isBot = $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|monitor|curl|wget|python/i', $ua);

    if (!$isBot) {
        $tz = new DateTimeZone('Europe/Budapest');
        $day = (new DateTime('now', $tz))->format('Y-m-d');
        $host = clean_host($_SERVER['HTTP_HOST'] ?? '');
        $from = clean_host((string)($_GET['from'] ?? ''));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $vhash = hash_hmac('sha256', $day . '|' . $ip . '|' . $ua, $cfg['secret']);
        if ($host !== '') {
            $st = $pdo->prepare('INSERT INTO visits (day, host, from_host, vhash) VALUES (?,?,?,?)
                ON CONFLICT(day, host, vhash) DO UPDATE SET hits = hits + 1');
            $st->execute([$day, $host, $from, $vhash]);
        }
    }
    $total = (int)$pdo->query('SELECT COUNT(*) FROM visits')->fetchColumn();
    echo json_encode(['total' => $total + (int)($cfg['start_offset'] ?? 0)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'counter_unavailable']);
}
