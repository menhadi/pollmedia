#!/bin/sh
# Installed only in the isolated census-worker directory, never the web app.
set -eu
umask 077
worker_root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
shared_root=$worker_root
if [ "$(basename "$worker_root")" = ocr-worker ]; then shared_root=$(dirname "$worker_root"); fi
if [ "${1:-}" != --capped ] && [ -f "$shared_root/cpu-trial-until" ]; then
    trial_until=$(cat "$shared_root/cpu-trial-until")
    if [ "$(date +%s)" -lt "$trial_until" ]; then
        exec /usr/bin/systemd-run --user --scope --quiet -p CPUQuota=50% -p CPUWeight=10 \
            /bin/sh "$worker_root/run_census_server_worker.sh" --capped
    fi
fi
ulimit -v 1572864
exec /usr/bin/nice -n 15 /usr/bin/ionice -c 3 /usr/bin/timeout 6h \
    "$worker_root/venv/bin/python" "$worker_root/census_server_worker.py" --root "$worker_root"
