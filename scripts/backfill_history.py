#!/usr/bin/env python3
"""Egyszeri visszatöltés: a data/dashboard.json összes korábbi git-változatából pillanatképeket készít.

Használat:  python3 scripts/backfill_history.py [history.json]
A repóban elérhető teljes előzményre van szükség (git fetch --unshallow vagy elég mély --depth).
"""
import json
import pathlib
import subprocess
import sys

sys.path.insert(0, str(pathlib.Path(__file__).parent))
from history_lib import load_history, snapshot, write_history  # noqa: E402


def git(*args):
    return subprocess.check_output(["git", *args], text=True)


def main(dst="data/history.json"):
    commits = git("log", "--reverse", "--format=%h", "--", "data/dashboard.json").split()
    rows = load_history(dst)
    for c in commits:
        try:
            doc = json.loads(git("show", f"{c}:data/dashboard.json"))
        except (subprocess.CalledProcessError, ValueError):
            continue
        rows.append(snapshot(doc))
    rows = write_history(dst, rows)
    print(f"{dst}: {len(rows)} pillanatkép, {rows[0]['t']} – {rows[-1]['t']}")


if __name__ == "__main__":
    main(*sys.argv[1:2])
