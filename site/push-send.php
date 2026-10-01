<?php
/*
 * Értesítés küldése új tételekről. A GitHub Action hívja meg, ha a data/dashboard.json megváltozott:
 *   POST push-send.php?sha=<commit>   X-Push-Token: <push_token>
 * Új tétel: ellenőrzött (verified) items-tétel vagy új, nem összesítő, nem elutasított feljelentett ügy.
 * Frissített tétel: már ismert tétel, amelynek az updated_at értéke megváltozott.
 * Az első futás csak megjegyzi a meglévő tételeket, értesítést nem küld.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
require __DIR__ . '/push-lib.php';

function out(array $a, int $code = 200): never { http_response_code($code); echo json_encode($a); exit; }

try {
    $cfg = push_cfg();
    $given = (string)($_SERVER['HTTP_X_PUSH_TOKEN'] ?? '');
    $token = (string)($cfg['push_token'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $token === '' || !hash_equals($token, $given)) {
        usleep(300000); out(['error' => 'forbidden'], 403);
    }
    $sha = (string)($_GET['sha'] ?? 'main');
    if (!preg_match('/^([0-9a-f]{40}|main)$/', $sha)) out(['error' => 'bad_sha'], 400);

    $url = rtrim($cfg['data_raw_base'] ?? 'https://raw.githubusercontent.com/Hujberr/vagyonvisszaszerzes/', '/') . '/' . $sha . '/data/dashboard.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = $code === 200 ? json_decode((string)$raw, true) : null;
    if (!is_array($data)) out(['error' => 'data_unavailable'], 502);

    $cat = ['hu' => ['visszaszerzes' => 'Visszaszerzés', 'megtakaritas' => 'Megtakarítás', 'eu_forras' => 'EU-forrás', 'rc' => 'Feljelentett ügy'],
            'en' => ['visszaszerzes' => 'Recovered assets', 'megtakaritas' => 'Savings', 'eu_forras' => 'EU funds', 'rc' => 'Reported case']];
    $current = [];
    foreach ($data['items'] ?? [] as $it) {
        if (($it['status'] ?? '') === 'verified' && !empty($it['id'])) $current['i:' . $it['id']] = [$it['category'] ?? '', (string)($it['subject'] ?? ''), (string)($it['updated_at'] ?? md5(json_encode($it)))];
    }
    foreach ($data['reported_cases'] ?? [] as $rc) {
        if (empty($rc['is_aggregate']) && ($rc['status'] ?? '') !== 'dismissed' && !empty($rc['id'])) $current['r:' . $rc['id']] = ['rc', (string)($rc['subject'] ?? ''), (string)($rc['updated_at'] ?? md5(json_encode($rc)))];
    }

    $pdo = push_db($cfg);
    $seenRaw = push_state_get($pdo, 'seen');
    $seen = $seenRaw === null ? null : (json_decode($seenRaw, true) ?: []);
    $verRaw = push_state_get($pdo, 'ver');
    $ver = $verRaw === null ? null : (json_decode($verRaw, true) ?: []);
    $new = $seen === null ? [] : array_diff_key($current, array_flip($seen));
    $upd = [];
    if ($ver !== null) {
        foreach ($current as $k => $v) {
            if (!isset($new[$k]) && isset($ver[$k]) && $ver[$k] !== $v[2]) $upd[$k] = $v;
        }
    }
    push_state_set($pdo, 'seen', json_encode(array_values(array_unique(array_merge($seen ?? [], array_keys($current))))));
    push_state_set($pdo, 'ver', json_encode(array_map(fn($v) => $v[2], $current)));
    /* A honlapon színes kerettel kiemelt kártyák = az ebben a futásban kiküldött értesítés tételei. */
    push_state_set($pdo, 'hl', json_encode(['new' => array_keys($new), 'upd' => array_keys($upd)]));
    if ($seen === null) out(['initialized' => true, 'known' => count($current)]);
    if (!$new && !$upd) out(['new' => 0, 'updated' => 0, 'sent' => 0]);

    $n = count($new); $u = count($upd);
    $all = $new + $upd;
    [$c0, $s0] = reset($all);
    $t = $n + $u;
    $msg = [];
    foreach (['hu', 'en'] as $l) {
        $hu = $l === 'hu';
        $label = $cat[$l][$c0] ?? ($hu ? 'Tétel' : 'Item');
        if ($t === 1) {
            $title = $n === 1 ? ($hu ? 'Új tétel: ' : 'New item: ') . $label : ($hu ? 'Frissített tétel: ' : 'Updated item: ') . $label;
            $body = $s0;
        } else {
            $parts = [];
            if ($n) $parts[] = $hu ? "$n új tétel" : "$n new";
            if ($u) $parts[] = $hu ? "$u frissítés" : "$u updated";
            $title = implode($hu ? ', ' : ', ', $parts) . ($hu ? ' az oldalon' : ' items');
            $body = $s0 . ($hu ? ' és még ' . ($t - 1) . ' tétel' : ' and ' . ($t - 1) . ' more');
        }
        $msg[$l] = ['title' => $title, 'body' => $body, 'url' => './'];
    }
    push_state_set($pdo, 'latest', json_encode($msg, JSON_UNESCAPED_UNICODE));
    out(['new' => $n, 'updated' => $u] + push_send($pdo, $cfg));
} catch (Throwable $e) {
    out(['error' => 'failed'], 500);
}
