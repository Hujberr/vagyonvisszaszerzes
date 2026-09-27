<?php
/*
 * Értesítési feliratkozás kezelése.
 *   GET  ?action=key                 → {"key": VAPID nyilvános kulcs}
 *   GET  ?action=latest&ep=<cím>     → a legutóbbi értesítés szövege (a service worker kéri le)
 *   POST {"action":"subscribe","endpoint":…,"lang":"hu|en"}
 *   POST {"action":"unsubscribe","endpoint":…}
 * Csak a böngésző által adott feliratkozási címet és a nyelvet tárolja.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/push-lib.php';

function out(array $a, int $code = 200): never { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

try {
    $cfg = push_cfg();
    if (($cfg['vapid_public'] ?? '') === '' || ($cfg['vapid_private_pem'] ?? '') === '') out(['error' => 'not_configured'], 503);
    $pdo = push_db($cfg);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = (string)($_GET['action'] ?? '');
        if ($action === 'key') out(['key' => $cfg['vapid_public']]);
        if ($action === 'latest') {
            $ep = (string)($_GET['ep'] ?? '');
            $lang = 'hu';
            if ($ep !== '') {
                $s = $pdo->prepare('SELECT lang FROM subs WHERE endpoint = ?'); $s->execute([$ep]);
                $lang = ($s->fetchColumn() === 'en') ? 'en' : 'hu';
                $test = push_state_get($pdo, 'test:' . sha1($ep));
                if ($test !== null && (int)$test > time()) {
                    out($lang === 'en'
                        ? ['title' => 'Test notification', 'body' => 'Notifications are working on this device.', 'url' => './']
                        : ['title' => 'Tesztértesítés', 'body' => 'Az értesítések működnek ezen az eszközön.', 'url' => './']);
                }
            }
            $latest = json_decode(push_state_get($pdo, 'latest') ?? 'null', true);
            if (!is_array($latest) || !isset($latest[$lang])) out(['error' => 'no_message'], 404);
            out($latest[$lang]);
        }
        out(['error' => 'bad_request'], 400);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'method'], 405);
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 4096), true);
    if (!is_array($in)) out(['error' => 'bad_request'], 400);
    $ep = (string)($in['endpoint'] ?? '');
    if (!push_valid_endpoint($ep)) out(['error' => 'bad_endpoint'], 400);

    if (($in['action'] ?? '') === 'subscribe') {
        if ((int)$pdo->query('SELECT COUNT(*) FROM subs')->fetchColumn() > 100000) out(['error' => 'full'], 503);
        $lang = ($in['lang'] ?? '') === 'en' ? 'en' : 'hu';
        $pdo->prepare('INSERT INTO subs (endpoint, lang, created) VALUES (?, ?, ?)
            ON CONFLICT(endpoint) DO UPDATE SET lang = excluded.lang')->execute([$ep, $lang, gmdate('c')]);
        out(['ok' => true]);
    }
    if (($in['action'] ?? '') === 'unsubscribe') {
        $pdo->prepare('DELETE FROM subs WHERE endpoint = ?')->execute([$ep]);
        out(['ok' => true]);
    }
    out(['error' => 'bad_request'], 400);
} catch (Throwable $e) {
    out(['error' => 'unavailable'], 500);
}
