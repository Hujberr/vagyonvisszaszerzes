"""Közös számítás a történet-fájlhoz (data/history.json).

A négy főkártya összegét ugyanazzal a logikával számolja, mint a honlap (site/index.html):
 - Visszaszerzés: ellenőrzött (verified) visszaszerzés tételek összege
 - Megtakarítás: a megtakarítás kategória összes tétele (státusztól függetlenül)
 - EU beérkezett: ellenőrzött EU-forrás tételek `received_huf` összege
 - Feljelentett ügyek: az összesítő (is_aggregate) dokumentum összege, ennek hiányában az ügyek összege
Minden tétel az esemény (event_date / report_date) napjától számít, a pillanatkép napjáig.
Az összegek milliárd forintban, 3 tizedesre kerekítve kerülnek a fájlba.
"""
import json
import pathlib

FIELDS = ("r", "s", "e", "f")  # visszaszerzés, megtakarítás, EU beérkezett, feljelentett


def _num(v):
    return v if isinstance(v, (int, float)) and not isinstance(v, bool) else 0


def snapshot(doc):
    """Egy dashboard.json tartalmából egyetlen pillanatképet készít (idő: generated_at)."""
    t = doc.get("generated_at") or ""
    cutoff = t[:10] or "9999-12-31"
    items = [d for d in doc.get("items", []) if d.get("status") != "rejected"
             and (d.get("event_date") or "0000-00-00") <= cutoff]
    reported = [d for d in doc.get("reported_cases", [])
                if (d.get("report_date") or "0000-00-00") <= cutoff]
    rec = sum(_num(d.get("amount_huf")) for d in items
              if d.get("category") == "visszaszerzes" and d.get("status") == "verified")
    sav = sum(_num(d.get("amount_huf")) for d in items if d.get("category") == "megtakaritas")
    eu = sum(_num(d.get("received_huf")) for d in items
             if d.get("category") == "eu_forras" and d.get("status") == "verified")
    agg = next((d for d in reported if d.get("is_aggregate") is True), None)
    rep = (agg.get("alleged_amount_huf") or 0) if agg else sum(_num(d.get("alleged_amount_huf")) for d in reported)
    vals = [round(v / 1e9, 3) for v in (rec, sav, eu, rep)]
    return {"t": t, **dict(zip(FIELDS, vals))}


def load_history(path):
    p = pathlib.Path(path)
    if not p.is_file():
        return []
    try:
        data = json.loads(p.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return []
    return data if isinstance(data, list) else []


def write_history(path, rows):
    rows = sorted({r["t"]: r for r in rows if r.get("t")}.values(), key=lambda r: r["t"])
    body = ",\n".join(json.dumps(r, ensure_ascii=False, separators=(",", ":")) for r in rows)
    pathlib.Path(path).write_text("[\n" + body + "\n]\n", encoding="utf-8")
    return rows
