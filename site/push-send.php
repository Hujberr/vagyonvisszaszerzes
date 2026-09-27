<?php
/*
 * Értesítés küldése új tételekről. A GitHub Action hívja meg, ha a data/dashboard.json megváltozott:
 *   POST push-send.php?sha=<commit>   X-Push-Token: <push_token>
 * Új tétel: ellenőrzött (verified) items-tétel vagy új, nem összesítő, nem elutasított feljelentett ügy.
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
        if (($it['status'] ?? '') === 'verified' && !empty($it['id'])) $current['i:' . $it['id']] = [$it['category'] ?? '', (string)($it['subject'] ?? '')];
    }
    foreach ($data['reported_cases'] ?? [] as $rc) {
        if (empty($rc['is_aggregate']) && ($rc['status'] ?? '') !== 'dismissed' && !empty($rc['id'])) $current['r:' . $rc['id']] = ['rc', (string)($rc['subject'] ?? '')];
    }

    $pdo = push_db($cfg);
    $seenRaw = push_state_get($pdo, 'seen');
    $seen = $seenRaw === null ? null : (json_decode($seenRaw, true) ?: []);
    $new = $seen === null ? [] : array_diff_key($current, array_flip($seen));
    push_state_set($pdo, 'seen', json_encode(array_values(array_unique(array_merge($seen ?? [], array_keys($current))))));
    if ($seen === null) out(['initialized' => true, 'known' => count($current)]);
    if (!$new) out(['new' => 0, 'sent' => 0]);

    $n = count($new);
    [$c0, $s0] = reset($new);
    $msg = [];
    foreach (['hu', 'en'] as $l) {
        $label = $cat[$l][$c0] ?? ($l === 'hu' ? 'Új tétel' : 'New item');
        $msg[$l] = $n === 1
            ? ['title' => ($l === 'hu' ? 'Új tétel: ' : 'New item: ') . $label, 'body' => $s0, 'url' => './']
            : ['title' => $l === 'hu' ? "$n új tétel került fel" : "$n new items added",
               'body' => $s0 . ($l === 'hu' ? ' és még ' . ($n - 1) . ' tétel' : ' and ' . ($n - 1) . ' more'), 'url' => './'];
    }
    push_state_set($pdo, 'latest', json_encode($msg, JSON_UNESCAPED_UNICODE));
    out(['new' => $n] + push_send($pdo, $cfg));
} catch (Throwable $e) {
    out(['error' => 'failed'], 500);
}
