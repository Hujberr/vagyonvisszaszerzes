/* Service worker: telepíthetőség, offline tartalék a kezdőlapra, push-értesítések megjelenítése. */
const CACHE = "vv-v1";
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", e => e.waitUntil(self.clients.claim()));

/* Csak a kezdőlap: hálózatról, hiba esetén a legutóbb elmentett változat. */
self.addEventListener("fetch", e => {
  if (e.request.mode !== "navigate") return;
  const u = new URL(e.request.url);
  if (u.origin !== location.origin || !(u.pathname.endsWith("/") || u.pathname.endsWith("/index.html"))) return;
  e.respondWith(fetch(e.request, { cache: "no-cache" }).then(r => {
    if (r.ok) { const c = r.clone(); caches.open(CACHE).then(x => x.put("./", c)); }
    return r;
  }).catch(() => caches.match("./").then(r => r || Response.error())));
});

/* Tartalom nélküli push: a szöveget a szerverről kérjük le. */
self.addEventListener("push", e => {
  e.waitUntil((async () => {
    let d = { title: "Vagyonvisszaszerzési Monitor", body: "Új vagy frissített tétel került fel.", url: "./" };
    let ep = "";
    try { const s = await self.registration.pushManager.getSubscription(); ep = s ? s.endpoint : ""; } catch (_) {}
    /* A telefon a push után gyakran még nincs online: legfeljebb 5 próbálkozás, növekvő várakozással. */
    for (let i = 0; i < 5; i++) {
      try {
        const c = new AbortController(); const to = setTimeout(() => c.abort(), 8000);
        const r = await fetch("push-subscribe.php?action=latest&ep=" + encodeURIComponent(ep), { cache: "no-store", signal: c.signal });
        clearTimeout(to);
        if (r.ok) { d = Object.assign(d, await r.json()); break; }
        if (r.status === 404) break;
      } catch (_) {}
      await new Promise(res => setTimeout(res, 1500 * (i + 1)));
    }
    await self.registration.showNotification(d.title, { body: d.body, icon: "icon-192.png", badge: "icon-192.png", tag: "vv-new", data: { url: d.url } });
  })());
});

self.addEventListener("notificationclick", e => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || "./", self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(list => {
    for (const c of list) { if (c.url.startsWith(self.registration.scope) && "focus" in c) { c.navigate(url).catch(() => {}); return c.focus(); } }
    return self.clients.openWindow(url);
  }));
});
