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


# Érdemi (tartalmi) mezők: ezek változása "módosult"; minden más változás (új forrás, leírás,
# megjegyzés) csak "megerősítve". Az "érintett" = új + módosult + csak megerősítve.
_SUBSTANTIVE = {
    "items": ("category", "status", "amount_huf", "agreed_huf", "agreed_original_huf",
              "received_huf", "lost_huf", "lost_items", "subject"),
    "reported_cases": ("status", "alleged_amount_huf", "confirmed_recovered_huf",
                       "recovered_item_id", "subject"),
}
HISTORY_LIMIT = 100


def classify(docs, prev_by_id, section, key_prefix, counted):
    """Egy szekció tételeit új / módosult / csak megerősítve csoportba sorolja az előző állapot alapján."""
    res = {"new": [], "modified": [], "confirmed_only": []}
    for d in docs:
        if not counted(d):
            continue
        prev = prev_by_id.get(d["id"])
        key = key_prefix + d["id"]
        if prev is None:
            res["new"].append(key)
            continue
        pc = {k: v for k, v in prev.items() if k not in _STAMP_KEYS}
        nc = {k: v for k, v in d.items() if k not in _STAMP_KEYS}
        if pc == nc:
            continue
        if any(pc.get(k) != nc.get(k) for k in _SUBSTANTIVE[section]):
            res["modified"].append(key)
        else:
            res["confirmed_only"].append(key)
    return res


def build_run_summary(items, cases, prev_items, prev_cases, run_id):
    """A futás egyetlen, közös összesítője: ebből készül az oldal számlálója, a kiemelés és a push szövege.
    Csak az ellenőrzött (verified) tételek és a nem összesítő feljelentett ügyek számítanak; az
    ellenőrzésre váró (needs_review) tételek külön, `pending_review` néven szerepelnek."""
    it = classify(items, prev_items, "items", "i:", lambda d: d.get("status") == "verified")
    rc = classify(cases, prev_cases, "reported_cases", "r:", lambda d: not d.get("is_aggregate"))
    tot = {k: len(it[k]) + len(rc[k]) for k in it}
    return {
        "run_id": run_id,
        "new": tot["new"], "modified": tot["modified"], "confirmed_only": tot["confirmed_only"],
        "touched": tot["new"] + tot["modified"] + tot["confirmed_only"],
        "pending_review": sum(1 for d in items if d.get("status") == "needs_review"),
        "by_section": {"items": {k: len(v) for k, v in it.items()},
                       "reported_cases": {k: len(v) for k, v in rc.items()}},
        "new_ids": it["new"] + rc["new"],
        "modified_ids": it["modified"] + rc["modified"],
        "confirmed_ids": it["confirmed_only"] + rc["confirmed_only"],
    }


def load_prev_summaries(dst: pathlib.Path):
    try:
        prev = json.loads(dst.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return [], None
    return prev.get("run_summary_history") or [], prev


def seed_history_from_runs(run_history):
    """Első használatkor a régi futásnaplóból tölti fel az előzményt (az érdemi/megerősített bontás nem ismert)."""
    out = []
    for r in run_history:
        run_id = r.get("finished_at") or r.get("started_at")
        if not run_id:
            continue
        out.append({"run_id": run_id, "legacy": True,
                    "by_section": {"items": {"new": int(r.get("items_new") or 0), "modified": 0, "confirmed_only": 0},
                                   "reported_cases": {"new": int(r.get("reported_cases_new") or 0),
                                                      "modified": int(r.get("reported_cases_updated") or 0),
                                                      "confirmed_only": 0}}})
    return out


def main(src, dst):
    src = pathlib.Path(src)
    dst = pathlib.Path(dst)
    items = [d for d in load_dir(src / "items") if d.get("status") != "rejected"]
    cases = [d for d in load_dir(src / "reported_cases") if d.get("status") != "dismissed"]
    prev_items_by_id, prev_cases_by_id = load_prev(dst)
    generated_at = datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds")
    # Az összesítő az előző publikált állapot és az új export különbségéből készül (a bélyegzés előtt,
    # hogy a stamp-mezők ne számítsanak változásnak).
    run_summary = build_run_summary(items, cases, prev_items_by_id, prev_cases_by_id, generated_at)
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

    history, prev_doc = load_prev_summaries(dst)
    if not history:
        history = seed_history_from_runs(run_history)
    # Csak a számlálókat őrizzük meg az előzményben (azonosítólisták nélkül), futásonként egy bejegyzés.
    slim = {"run_id": run_summary["run_id"], "by_section": run_summary["by_section"]}
    history = [h for h in history if h.get("run_id") != slim["run_id"]] + [slim]
    history = history[-HISTORY_LIMIT:]

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
        "run_summary": run_summary,
        "run_summary_history": history,
    }
    dst.parent.mkdir(parents=True, exist_ok=True)
    dst.write_text(json.dumps(out, ensure_ascii=False, indent=1, sort_keys=False), encoding="utf-8")
    print(f"{dst}: {len(items)} tétel, {len(cases)} feljelentett ügy")
    print("RUN_SUMMARY Új: {new} · Módosult: {modified} · Megerősítve: {confirmed_only} · "
          "Érintett: {touched} · Ellenőrzésre vár: {pending_review}".format(**run_summary))


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
