<?php
/* Látogatottsági kimutatás — jelszóval védett, csak az üzemeltetőnek. */
declare(strict_types=1);
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$cfg = require __DIR__ . '/counter-config.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }
$err = '';
/* Tesztértesítés: csak a bejelentkezett üzemeltető, csak a saját eszköze feliratkozására. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'testpush') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['ok'])) { http_response_code(403); echo '{"error":"forbidden"}'; exit; }
    try {
        require __DIR__ . '/push-lib.php';
        $ep = (string)($_POST['endpoint'] ?? '');
        $pdo = push_db($cfg);
        $s = $pdo->prepare('SELECT 1 FROM subs WHERE endpoint = ?'); $s->execute([$ep]);
        if (!$s->fetchColumn()) { echo '{"error":"not_subscribed"}'; exit; }
        push_state_set($pdo, 'test:' . sha1($ep), (string)(time() + 300));
        echo json_encode(push_send($pdo, $cfg, [$ep]));
    } catch (Throwable $e) { http_response_code(500); echo '{"error":"failed"}'; }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    usleep(400000);
    if (($cfg['admin_password_hash'] ?? '') !== '' && password_verify((string)($_POST['pw'] ?? ''), $cfg['admin_password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        header('Location: admin.php'); exit;
    }
    $err = 'Hibás jelszó.';
}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Látogatottság</title>
<style>
:root{--bg:#080C16;--card:#0E1526;--bd:#1E2A40;--tx:#EDF1FA;--dim:#8B97AC;--ac:#2BD9FF}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font:14px system-ui,sans-serif;padding:16px}
.wrap{max-width:900px;margin:0 auto}.card{background:var(--card);border:1px solid var(--bd);border-radius:14px;padding:16px;margin-bottom:14px;overflow-x:auto}
h1{font-size:18px;margin:0 0 14px}h2{font-size:14px;margin:0 0 10px;color:var(--ac)}
table{border-collapse:collapse;width:100%;font-variant-numeric:tabular-nums}th,td{padding:7px 10px;border-bottom:1px solid var(--bd);text-align:right;white-space:nowrap}
th:first-child,td:first-child{text-align:left}th{color:var(--dim);font-size:12px;font-weight:600}
input,button{font:inherit;padding:10px 12px;border-radius:9px;border:1px solid var(--bd);background:var(--bg);color:var(--tx)}button{background:var(--ac);color:#04141F;font-weight:700;cursor:pointer}
a{color:var(--ac)}.err{color:#FF3B6B}.kpi{display:flex;gap:14px;flex-wrap:wrap}.kpi div{flex:1;min-width:130px}.kpi b{display:block;font-size:22px}
</style></head><body><div class="wrap">
<?php if (empty($_SESSION['ok'])): ?>
  <div class="card"><h1>Látogatottság — belépés</h1>
  <form method="post"><input type="password" name="pw" placeholder="Jelszó" autofocus required> <button>Belépés</button></form>
  <?php if ($err) echo '<p class="err">' . $h($err) . '</p>'; ?></div>
<?php else:
  $pdo = new PDO('sqlite:' . $cfg['db_path']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
  $since = (new DateTime('now', new DateTimeZone('Europe/Budapest')))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
  $q = fn($sql, $p = []) => (function() use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); })();
  $tot = $q('SELECT COUNT(*) u, COALESCE(SUM(hits),0) v FROM visits')[0];
  $per = $q('SELECT COUNT(*) u, COALESCE(SUM(hits),0) v FROM visits WHERE day >= ?', [$since])[0];
  $hosts = $q('SELECT host, COUNT(*) u, SUM(hits) v FROM visits WHERE day >= ? GROUP BY host ORDER BY u DESC', [$since]);
  $froms = $q('SELECT from_host, COUNT(*) u FROM visits WHERE day >= ? AND from_host <> "" GROUP BY from_host ORDER BY u DESC', [$since]);
  $daily = $q('SELECT day, host, COUNT(*) u FROM visits WHERE day >= ? GROUP BY day, host ORDER BY day DESC', [$since]);
  $hostList = array_column($hosts, 'host');
  $grid = [];
  foreach ($daily as $r) { $grid[$r['day']][$r['host']] = (int)$r['u']; }
?>
  <div class="card"><h1>Látogatottság</h1>
    <p>Időszak: <?php foreach ([7,30,90,365] as $d) echo '<a href="?days='.$d.'">'.$d.' nap</a> &nbsp;'; ?> · <a href="?logout=1">Kilépés</a></p>
    <div class="kpi">
      <div>Napi egyedi látogatás, összesen<b><?= number_format((int)$tot['u'], 0, ',', ' ') ?></b></div>
      <div>Utolsó <?= $days ?> nap<b><?= number_format((int)$per['u'], 0, ',', ' ') ?></b></div>
      <div>Oldalmegnyitás, utolsó <?= $days ?> nap<b><?= number_format((int)$per['v'], 0, ',', ' ') ?></b></div>
    </div></div>
  <div class="card"><h2>Domainenként (utolsó <?= $days ?> nap)</h2>
    <table><tr><th>Domain</th><th>Egyedi</th><th>Megnyitás</th></tr>
    <?php foreach ($hosts as $r) echo '<tr><td>'.$h($r['host']).'</td><td>'.$h($r['u']).'</td><td>'.$h($r['v']).'</td></tr>'; ?>
    </table></div>
  <?php if ($froms): ?>
  <div class="card"><h2>Átirányított domainekről érkezett (utolsó <?= $days ?> nap)</h2>
    <table><tr><th>Eredeti domain</th><th>Egyedi</th></tr>
    <?php foreach ($froms as $r) echo '<tr><td>'.$h($r['from_host']).'</td><td>'.$h($r['u']).'</td></tr>'; ?>
    </table></div>
  <?php endif; ?>
  <div class="card"><h2>Napi bontás (egyedi látogatók)</h2>
    <table><tr><th>Nap</th><?php foreach ($hostList as $x) echo '<th>'.$h($x).'</th>'; ?><th>Összesen</th></tr>
    <?php foreach ($grid as $day => $row) { echo '<tr><td>'.$h($day).'</td>'; foreach ($hostList as $x) echo '<td>'.($row[$x] ?? 0).'</td>'; echo '<td>'.array_sum($row).'</td></tr>'; } ?>
    </table></div>
  <div class="card"><h2>Push-értesítés</h2>
    <?php
      try { require_once __DIR__ . '/push-lib.php'; $subs = (int)push_db($cfg)->query('SELECT COUNT(*) FROM subs')->fetchColumn(); }
      catch (Throwable $e) { $subs = null; }
    ?>
    <p>Feliratkozók: <b><?= $subs === null ? 'nincs beállítva' : number_format($subs, 0, ',', ' ') ?></b></p>
    <p><button id="testPush" type="button">Tesztértesítés erre az eszközre</button> <span id="testPushMsg"></span></p>
    <p style="color:var(--dim);font-size:12px">Előbb a honlapon a csengő ikonnal iratkozz fel ugyanebben a böngészőben.</p>
  </div>
  <script>
  document.getElementById('testPush').addEventListener('click', async () => {
    const m = document.getElementById('testPushMsg');
    try {
      const reg = await navigator.serviceWorker.getRegistration('./');
      const sub = reg && await reg.pushManager.getSubscription();
      if (!sub) { m.textContent = 'Ez az eszköz nincs feliratkozva.'; return; }
      const r = await fetch('admin.php', {method: 'POST', body: new URLSearchParams({action: 'testpush', endpoint: sub.endpoint})});
      const j = await r.json();
      m.textContent = j.sent ? 'Elküldve.' : (j.error === 'not_subscribed' ? 'A feliratkozás nem található a szerveren.' : 'Sikertelen küldés.');
    } catch (e) { m.textContent = 'Sikertelen küldés.'; }
  });
  </script>
<?php endif; ?>
</div></body></html>
