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
  <?php
    $n = fn($x) => number_format((int)$x, 0, ',', ' ');
    $sq = function ($sql, $p = []) use ($pdo) { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } };
    $evTot = []; foreach ($sq('SELECT ev, COUNT(*) c FROM install_events GROUP BY ev') as $r) $evTot[$r['ev']] = (int)$r['c'];
    $evPer = []; foreach ($sq('SELECT ev, COUNT(*) c FROM install_events WHERE day >= ? GROUP BY ev', [$since]) as $r) $evPer[$r['ev']] = (int)$r['c'];
    $evBr = $sq('SELECT browser, platform,
        SUM(ev = "installed") inst, SUM(ev = "first_app") firsts, SUM(ev = "guide") guide
        FROM install_events WHERE day >= ? GROUP BY browser, platform ORDER BY firsts DESC, inst DESC', [$since]);
    $appPer = $sq('SELECT COUNT(*) u, COALESCE(SUM(hits),0) v FROM app_visits WHERE day >= ?', [$since])[0] ?? ['u' => 0, 'v' => 0];
    $appDaily = [];
    foreach ($sq('SELECT day, COUNT(*) u, SUM(hits) v FROM app_visits WHERE day >= ? GROUP BY day', [$since]) as $r) $appDaily[$r['day']] = ['u' => $r['u'], 'v' => $r['v']];
    foreach ($sq('SELECT day, COUNT(*) f FROM install_events WHERE ev = "first_app" AND day >= ? GROUP BY day', [$since]) as $r) $appDaily[$r['day']]['f'] = $r['f'];
    krsort($appDaily);
    $brName = ['chrome' => 'Chrome', 'edge' => 'Edge', 'samsung' => 'Samsung Internet', 'opera' => 'Opera', 'firefox' => 'Firefox',
        'ios-safari' => 'Safari (iPhone)', 'ios-chrome' => 'Chrome (iPhone)', 'ios-firefox' => 'Firefox (iPhone)', 'ios-edge' => 'Edge (iPhone)', 'egyeb' => 'Egyéb'];
  ?>
  <div class="card"><h2>Kezdőképernyős app</h2>
    <div class="kpi">
      <div>Első app-indítás (telepítés), összesen<b><?= $n($evTot['first_app'] ?? 0) ?></b></div>
      <div>Első app-indítás, utolsó <?= $days ?> nap<b><?= $n($evPer['first_app'] ?? 0) ?></b></div>
      <div>Igazolt telepítés (Chrome, Edge, Samsung), utolsó <?= $days ?> nap<b><?= $n($evPer['installed'] ?? 0) ?></b></div>
      <div>Kézi útmutató megnyitva, utolsó <?= $days ?> nap<b><?= $n($evPer['guide'] ?? 0) ?></b></div>
      <div>App-használat, napi egyedi, utolsó <?= $days ?> nap<b><?= $n($appPer['u']) ?></b></div>
      <div>App-megnyitás, utolsó <?= $days ?> nap<b><?= $n($appPer['v']) ?></b></div>
    </div>
    <p style="color:var(--dim);font-size:12px">Az első app-indítás minden platformon mér (iPhone-on is), ez a legjobb telepítésszám. Az igazolt telepítést csak a Chrome, az Edge és a Samsung Internet jelzi. A kézi útmutató megnyitása érdeklődést mutat, nem telepítést. Az app-használat a nyilvános látogatószámban is benne van.</p>
  </div>
  <?php if ($evBr): ?>
  <div class="card"><h2>Böngészőnként (utolsó <?= $days ?> nap)</h2>
    <table><tr><th>Böngésző</th><th>Platform</th><th>Első app-indítás</th><th>Igazolt telepítés</th><th>Kézi útmutató</th></tr>
    <?php foreach ($evBr as $r) echo '<tr><td>'.$h($brName[$r['browser']] ?? $r['browser']).'</td><td>'.$h(['android' => 'Android', 'ios' => 'iPhone / iPad'][$r['platform']] ?? 'Egyéb').'</td><td>'.(int)$r['firsts'].'</td><td>'.(int)$r['inst'].'</td><td>'.(int)$r['guide'].'</td></tr>'; ?>
    </table></div>
  <?php endif; ?>
  <?php if ($appDaily): ?>
  <div class="card"><h2>App napi bontás</h2>
    <table><tr><th>Nap</th><th>Egyedi app-használó</th><th>App-megnyitás</th><th>Első app-indítás</th></tr>
    <?php foreach ($appDaily as $d => $r) echo '<tr><td>'.$h($d).'</td><td>'.(int)($r['u'] ?? 0).'</td><td>'.(int)($r['v'] ?? 0).'</td><td>'.(int)($r['f'] ?? 0).'</td></tr>'; ?>
    </table></div>
  <?php endif; ?>
  <div class="card"><h2>Push-értesítés</h2>
    <?php
      $subs = null; $logTot = []; $logDaily = [];
      try {
        require_once __DIR__ . '/push-lib.php';
        $pp = push_db($cfg);
        $subs = (int)$pp->query('SELECT COUNT(*) FROM subs')->fetchColumn();
        $st = $pp->prepare('SELECT kind, SUM(n) c FROM sub_log WHERE day >= ? GROUP BY kind'); $st->execute([$since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $logTot[$r['kind']] = (int)$r['c'];
        $st = $pp->prepare('SELECT day, kind, n FROM sub_log WHERE day >= ? ORDER BY day DESC'); $st->execute([$since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $logDaily[$r['day']][$r['kind']] = (int)$r['n'];
      } catch (Throwable $e) {}
    ?>
    <div class="kpi">
      <div>Feliratkozók most<b><?= $subs === null ? 'nincs beállítva' : $n($subs) ?></b></div>
      <div>Új feliratkozás, utolsó <?= $days ?> nap<b><?= $n($logTot['new'] ?? 0) ?></b></div>
      <div>Leiratkozás, utolsó <?= $days ?> nap<b><?= $n($logTot['unsub'] ?? 0) ?></b></div>
      <div>Megszűnt (böngésző törölte), utolsó <?= $days ?> nap<b><?= $n($logTot['gone'] ?? 0) ?></b></div>
    </div>
    <?php if ($logDaily): ?>
    <table style="margin-top:12px"><tr><th>Nap</th><th>Új</th><th>Leiratkozott</th><th>Megszűnt</th></tr>
    <?php foreach ($logDaily as $d => $r) echo '<tr><td>'.$h($d).'</td><td>'.($r['new'] ?? 0).'</td><td>'.($r['unsub'] ?? 0).'</td><td>'.($r['gone'] ?? 0).'</td></tr>'; ?>
    </table>
    <?php endif; ?>
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
