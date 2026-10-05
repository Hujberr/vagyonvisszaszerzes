#!/usr/bin/env python3
"""A data/dashboard.json aktuális állapotából új pillanatképet fűz a data/history.json fájlba.

Használat:  python3 scripts/update_history.py [dashboard.json] [history.json]
Ugyanazzal az időbélyeggel (generated_at) újrafuttatva nem duplikál.
"""
import json
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).parent))
from history_lib import load_history, snapshot, write_history  # noqa: E402


def main(src="data/dashboard.json", dst="data/history.json"):
    doc = json.loads(pathlib.Path(src).read_text(encoding="utf-8"))
    snap = snapshot(doc)
    if not snap["t"]:
        print("Hiányzó generated_at, nincs pillanatkép.")
        return
    rows = write_history(dst, load_history(dst) + [snap])
    print(f"{dst}: {len(rows)} pillanatkép (utolsó: {snap})")


if __name__ == "__main__":
    main(*sys.argv[1:3])
