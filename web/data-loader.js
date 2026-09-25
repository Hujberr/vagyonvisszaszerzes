/*
 * Vagyonvisszaszerzés – adatbetöltő a független domainen futó honlaphoz.
 * 5 percenként lekéri a GitHubon lévő dashboard.json-t, és változás esetén
 * meghívja a honlap render függvényét.
 *
 * Beillesztés a honlapba:
 *   <script src="data-loader.js"></script>
 *   <script>
 *     VagyonData.start(function (data) { renderDashboard(data); });
 *   </script>
 *
 * GITHUB_USER: a GitHub-felhasználónév, amely alatt a tároló van.
 */
(function (global) {
  var GITHUB_USER = "Hujberr";
  var REPO = "vagyonvisszaszerzes";
  var BRANCH = "main";
  var URL = "https://raw.githubusercontent.com/" + GITHUB_USER + "/" + REPO + "/" + BRANCH + "/data/dashboard.json";
  var INTERVAL_MS = 5 * 60 * 1000;
  var lastStamp = null;

  function load(onData, onError) {
    fetch(URL + "?t=" + Math.floor(Date.now() / 60000), { cache: "no-store" })
      .then(function (r) { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); })
      .then(function (data) {
        if (data.generated_at !== lastStamp) {
          lastStamp = data.generated_at;
          onData(data);
        }
      })
      .catch(function (e) { if (onError) onError(e); else console.warn("Adatbetöltési hiba:", e); });
  }

  global.VagyonData = {
    url: URL,
    start: function (onData, onError) {
      load(onData, onError);
      setInterval(function () { load(onData, onError); }, INTERVAL_MS);
      document.addEventListener("visibilitychange", function () {
        if (!document.hidden) load(onData, onError);
      });
    }
  };
})(window);
