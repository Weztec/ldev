#!/usr/bin/env python3

import json
import os
import shlex
import sys
import tempfile

from supervisor.options import ServerOptions, UnhosedConfigParser

def fail(msg):
    print(json.dumps({"ok": False, "error": str(msg)}))
    sys.exit(0)

def program_names(path):
    parser = UnhosedConfigParser()
    try:
        parser.read(path)
    except Exception:
        return set()
    return {s for s in parser.sections() if s.startswith("program:")}

candidate, others = sys.argv[1], sys.argv[2:]

mine = program_names(candidate)
for other in others:
    clash = mine & program_names(other)
    if clash:
        fail("program name already used by another site's config (%s): %s"
             % (os.path.basename(other), ", ".join(sorted(clash))))

with tempfile.TemporaryDirectory() as tmp:
    main = os.path.join(tmp, "main.conf")
    with open(main, "w") as f:
        f.write("[supervisord]\nlogfile=%s/sd.log\npidfile=%s/sd.pid\n[include]\nfiles = %s\n"
                % (tmp, tmp, candidate))

    options = ServerOptions()
    options.configfile = main
    try:
        options.process_config(do_usage=False)
    except Exception as e:

        fail(str(e).replace(candidate, "<generated config>"))

    for group in options.process_group_configs:
        for proc in group.process_configs:

            try:
                shlex.split(proc.command)
            except ValueError as e:
                fail("command for %s is not valid: %s" % (proc.name, e))
            if proc.directory and not os.path.isdir(proc.directory):
                fail("directory for %s does not exist: %s" % (proc.name, proc.directory))

print(json.dumps({"ok": True}))
