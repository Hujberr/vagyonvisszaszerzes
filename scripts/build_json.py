#!/usr/bin/env python3
"""Az Artifact-adattárból (ArtifactData out_dir export) előállítja a data/dashboard.json fájlt.

Használat:
  python3 scripts/build_json.py <export_könyvtár> data/dashboard.json

Az export könyvtár szerkezete (ArtifactData list/query + out_dir):
  <export>/items/*.json
  <export>/reported_cases/*.json
  <export>/macro_snapshots/*.json   (a legfrissebb kerül be)
  <export>/run_log/*.json           (opcionális, a legfrissebb kerül be)
"""
import json, sys, pathlib, datetime

SCHEMA_VERSION = 1


def load_dir(p: pathlib.Path):
    out = []
    if not p.is_dir():
        return out
    for f in sorted(p.glob("*.json")):
        d = json.loads(f.read_text(encoding="utf-8"))
        d["id"] = f.stem
        out.append(d)
    return out


def latest(docs, key):
    return max(docs, key=lambda d: d.get(key) or d["id"]) if docs else None


def load_prev(dst: pathlib.Path):
    """Beolvassa az előzőleg publikált dashboard.json-t (ha van), id szerint indexelve."""
    if not dst.is_file():
        return {}, {}
    try:
        prev = json.loads(dst.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}, {}
    prev_items = {d["id"]: d for d in prev.get("items", []) if d.get("id")}
    prev_cases = {d["id"]: d for d in prev.get("reported_cases", []) if d.get("id")}
    return prev_items, prev_cases


_STAMP_KEYS = ("updated_at", "first_seen_at")


def stamp_updated_at(docs, prev_by_id, now_iso):
    """Beállítja minden tétel updated_at és first_seen_at mezőjét:
    - ha bármely (nem stamp-) mező változott az előző publikált állapothoz képest -> updated_at = now_iso
    - ha nem változott -> megtartja az előző updated_at értékét
    - új tételnél -> updated_at = first_seen_at = now_iso (első megjelenés)
    - meglévő tételnél first_seen_at mindig megtartja az eredeti (első) értékét
    A megjelenítés (a főoldalon "Frissítve: ..." jelzés) az updated_at > first_seen_at
    összevetésből dönti el, hogy egy tétel a bejelentés óta frissült-e.
    """
    for d in docs:
        prev = prev_by_id.get(d["id"])
        if prev is None:
            d["updated_at"] = now_iso
            d["first_seen_at"] = now_iso
            continue
        prev_content = {k: v for k, v in prev.items() if k not in _STAMP_KEYS}
        new_content = {k: v for k, v in d.items() if k not in _STAMP_KEYS}
        d["first_seen_at"] = prev.get("first_seen_at") or prev.get("updated_at") or now_iso
        if new_content != prev_content:
            d["updated_at"] = now_iso
        else:
            d["updated_at"] = prev.get("updated_at") or now_iso
    return docs


def main(src, dst):
    src = pathlib.Path(src)
    dst = pathlib.Path(dst)
    items = [d for d in load_dir(src / "items") if d.get("status") != "rejected"]
    cases = [d for d in load_dir(src / "reported_cases") if d.get("status") != "dismissed"]
    prev_items_by_id, prev_cases_by_id = load_prev(dst)
    generated_at = datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds")
    stamp_updated_at(items, prev_items_by_id, generated_at)
    stamp_updated_at(cases, prev_cases_by_id, generated_at)
    macro = latest(load_dir(src / "macro_snapshots"), "collected_at")
    runs = load_dir(src / "run_log")
    run = latest(runs, "finished_at")
    # Rövid futásnapló a „Legutóbbi ügyek” 2 napos összefoglalójához (utolsó 100 futás).
    run_keys = ("finished_at", "started_at", "items_new", "reported_cases_new",
                "reported_cases_updated", "eu_lost_items_new")
    run_history = [{k: r.get(k) for k in run_keys if k in r}
                   for r in sorted(runs, key=lambda d: d.get("finished_at") or d["id"], reverse=True)[:100]]

    def total(cat):
        return sum((d.get("amount_huf") or 0) for d in items
                   if d.get("category") == cat and d.get("status") == "verified")

    out = {
        "schema_version": SCHEMA_VERSION,
        "generated_at": generated_at,
        "summary": {
            "megtakaritas_verified_huf": total("megtakaritas"),
            "visszaszerzes_verified_huf": total("visszaszerzes"),
            "eu_forras_verified_huf": total("eu_forras"),
            "eu_forras_lost_huf": sum((d.get("lost_huf") or 0) for d in items
                                      if d.get("category") == "eu_forras" and d.get("status") == "verified"),
            "items_count": len(items),
            "reported_cases_count": len(cases),
        },
        "items": items,
        "reported_cases": cases,
        "macro": macro,
        "last_run": run,
        "run_history": run_history,
    }
    dst.parent.mkdir(parents=True, exist_ok=True)
    dst.write_text(json.dumps(out, ensure_ascii=False, indent=1, sort_keys=False), encoding="utf-8")
    print(f"{dst}: {len(items)} tétel, {len(cases)} feljelentett ügy")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
