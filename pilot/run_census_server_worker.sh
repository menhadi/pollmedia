#!/bin/sh
# Installed only in the isolated census-worker directory, never the web app.
set -eu
umask 077
worker_root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ulimit -v 1572864
exec /usr/bin/nice -n 15 /usr/bin/ionice -c 3 /usr/bin/timeout 6h \
    "$worker_root/venv/bin/python" "$worker_root/census_server_worker.py" --root "$worker_root"
