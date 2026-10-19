#!/usr/bin/env python3
"""Keeps database backups by the owner's rule (2026-10-19): every backup of the current week; of each earlier week
of the current month only its last backup; of each earlier month only its last backup, for the last
BW_BACKUP_KEEP_MONTHS months (12). The newest backup is never deleted.

Usage: prune.py <dir> [--now 2026-10-19T03:15:00Z] [--dry-run]
"""
import argparse
import os
import re
import sys
from datetime import datetime, timezone

NAME = re.compile(r"^db-(\d{4}-\d{2}-\d{2}T\d{6}Z)\.dump$")


def stamp(name):
    match = NAME.match(name)
    return datetime.strptime(match.group(1), "%Y-%m-%dT%H%M%SZ").replace(tzinfo=timezone.utc) if match else None


def keep(backups, now, months):
    """backups: list of (datetime, name). Returns the names to keep."""
    kept = set()
    week_now = now.isocalendar()[:2]
    month_now = (now.year, now.month)
    last_week, last_month = {}, {}

    for when, name in sorted(backups):
        week = when.isocalendar()[:2]
        month = (when.year, when.month)
        if week == week_now:
            kept.add(name)
        elif month == month_now:
            last_week[week] = name
        else:
            last_month[month] = name

    kept.update(last_week.values())
    for month in sorted(last_month)[-months:] if months > 0 else []:
        kept.add(last_month[month])
    if backups:
        kept.add(max(backups)[1])
    return kept


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("dir")
    parser.add_argument("--now")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    now = datetime.strptime(args.now, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc) if args.now else datetime.now(timezone.utc)
    months = int(os.environ.get("BW_BACKUP_KEEP_MONTHS", "12"))
    backups = [(stamp(name), name) for name in os.listdir(args.dir) if stamp(name)]
    kept = keep(backups, now, months)

    for _, name in sorted(backups):
        if name in kept:
            print("keep   " + name)
        else:
            print("delete " + name)
            if not args.dry_run:
                os.remove(os.path.join(args.dir, name))
    return 0


if __name__ == "__main__":
    sys.exit(main())
