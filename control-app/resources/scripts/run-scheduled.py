#!/usr/bin/env python3

import datetime
import json
import signal
import subprocess
import sys
import threading

MONTHS = {n: i + 1 for i, n in enumerate("jan feb mar apr may jun jul aug sep oct nov dec".split())}
DAYS = {n: i for i, n in enumerate("sun mon tue wed thu fri sat".split())}

def parse_field(text, lo, hi, names=None):
    values = set()
    for part in text.split(","):
        rng, _, step = part.partition("/")
        if step and not step.isdigit() or step == "0":
            raise ValueError("bad step in '%s'" % part)
        step = int(step) if step else 1

        def num(tok):
            tok = tok.lower()
            if names and tok in names:
                return names[tok]
            if not tok.isdigit():
                raise ValueError("bad value '%s'" % tok)
            return int(tok)

        if rng == "*":
            start, end = lo, hi
        elif "-" in rng:
            a, b = rng.split("-", 1)
            start, end = num(a), num(b)
        else:
            start = num(rng)
            end = hi if step > 1 else start
        if start < lo or end > hi or start > end:
            raise ValueError("'%s' is outside %d-%d" % (part, lo, hi))
        values.update(range(start, end + 1, step))
    return values

def parse_cron(expr):
    fields = expr.split()
    if len(fields) != 5:
        raise ValueError("expected 5 fields (minute hour day-of-month month day-of-week), got %d" % len(fields))
    minute, hour, dom, month, dow = fields
    days = parse_field(dow, 0, 7, DAYS)
    return {
        "minutes": sorted(parse_field(minute, 0, 59)),
        "hours": sorted(parse_field(hour, 0, 23)),
        "dom": parse_field(dom, 1, 31),
        "months": parse_field(month, 1, 12, MONTHS),
        "dow": {d % 7 for d in days},
        "dom_star": dom.startswith("*"),
        "dow_star": dow.startswith("*"),
    }

def day_matches(c, d):
    if d.month not in c["months"]:
        return False
    in_dom, in_dow = d.day in c["dom"], (d.isoweekday() % 7) in c["dow"]
    if c["dom_star"] or c["dow_star"]:
        return in_dom and in_dow
    return in_dom or in_dow

def next_run(c, after):
    t = after.replace(second=0, microsecond=0) + datetime.timedelta(minutes=1)
    day = t.date()
    for _ in range(366 * 8):
        if day_matches(c, day):
            for h in c["hours"]:
                for m in c["minutes"]:
                    cand = datetime.datetime(day.year, day.month, day.day, h, m)
                    if cand >= t:
                        return cand
        day += datetime.timedelta(days=1)
    raise ValueError("never runs (e.g. a day that doesn't exist in that month)")

def upcoming(expr, n):
    c = parse_cron(expr)
    out, t = [], datetime.datetime.now()
    for _ in range(n):
        t = next_run(c, t)
        out.append(t.strftime("%Y-%m-%d %H:%M"))
    return out

if sys.argv[1] == "--next":
    try:
        print(json.dumps({"ok": True, "next": upcoming(sys.argv[3], int(sys.argv[2]))}))
    except ValueError as e:
        print(json.dumps({"ok": False, "error": str(e)}))
    sys.exit(0)

expr = sys.argv[1]
command = sys.argv[sys.argv.index("--") + 1:]
cron = parse_cron(expr)
child = None
stopping = False

wake = threading.Event()

def log(msg):
    print("[%s] %s" % (datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"), msg), flush=True)

def on_term(signum, frame):
    global stopping
    stopping = True
    wake.set()
    if child and child.poll() is None:
        child.terminate()

signal.signal(signal.SIGTERM, on_term)
signal.signal(signal.SIGINT, on_term)

while not stopping:
    due = next_run(cron, datetime.datetime.now())
    log("next run at %s: %s" % (due.strftime("%Y-%m-%d %H:%M"), " ".join(command)))

    while not stopping and datetime.datetime.now() < due:
        wake.wait(min(30, max(0.5, (due - datetime.datetime.now()).total_seconds())))
    if stopping:
        break
    log("running")
    child = subprocess.Popen(command)
    code = child.wait()
    log("finished, exit code %s" % code)
    child = None
