# vagyonvisszaszerzes

A „Vagyonvisszaszerzés / Elszámoltatás” dashboard nyilvános adatfájlja.

- `data/dashboard.json` – az aktuális, ellenőrzött adatállomány (tételek, feljelentett ügyek, makroadatok). Az ütemezett adatgyűjtő futás minden alkalommal frissíti.
- `web/data-loader.js` – a honlapba illeszthető betöltő, amely 5 percenként lekéri a JSON-t.
- `scripts/build_json.py` – az adattár-exportból előállítja a JSON-t.
- `data/history.json` – a négy főkártya összegének pillanatképei (futásonként egy; Mrd Ft). A heti változás jelvény és a részletező lapok grafikonja ebből készül. A `.github/workflows/history.yml` minden `data/dashboard.json` frissítés után automatikusan új pillanatképet fűz hozzá (`scripts/update_history.py`); a `scripts/backfill_history.py` az egyszeri visszatöltéshez készült a git-előzményekből.

Nyilvános elérési út:
`https://raw.githubusercontent.com/Hujberr/vagyonvisszaszerzes/main/data/dashboard.json`

A tároló kizárólag a közzétett adatot tartalmazza; hozzáférési adatot, kulcsot nem.
