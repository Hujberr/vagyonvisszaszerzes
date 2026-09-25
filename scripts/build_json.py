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


def main(src, dst):
    src = pathlib.Path(src)
    items = [d for d in load_dir(src / "items") if d.get("status") != "rejected"]
    cases = [d for d in load_dir(src / "reported_cases") if d.get("status") != "dismissed"]
    macro = latest(load_dir(src / "macro_snapshots"), "collected_at")
    run = latest(load_dir(src / "run_log"), "finished_at")

    def total(cat):
        return sum((d.get("amount_huf") or 0) for d in items
                   if d.get("category") == cat and d.get("status") == "verified")

    out = {
        "schema_version": SCHEMA_VERSION,
        "generated_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
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
    }
    dst = pathlib.Path(dst)
    dst.parent.mkdir(parents=True, exist_ok=True)
    dst.write_text(json.dumps(out, ensure_ascii=False, indent=1, sort_keys=False), encoding="utf-8")
    print(f"{dst}: {len(items)} tétel, {len(cases)} feljelentett ügy")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
