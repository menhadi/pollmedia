# Shared server load diagnosis — 29 September 2026

Read-only measurements at approximately 07:41–07:42 UTC explain why the civic import admission gate has not opened. No services, jobs, limits, cron entries, application code or database records were changed.

## Findings

The six-CPU server is CPU-bound. One-minute load was 21.56; four one-second vmstat intervals showed 0–5% idle CPU and approximately zero I/O wait. CPU pressure `some avg10` was 75.06%. Swap traffic was zero during those samples; occupied swap alone is not the problem. Disk had approximately 66 GiB available.

A separate four-second process sample grouped CPU time by owner and executable. Percentages use one CPU core as 100%; these are sampled contributions, not exact sustained attribution. Processes that started or exited during the interval are not fully counted.

| Owner / executable | CPU equivalent | Processes at end of sample |
| --- | --- | --- |
| Examelite Python | 1.62 cores | 4 |
| Examelite Tesseract OCR | 1.46 cores | 1 |
| Pollmedia PHP-FPM | 1.24 cores | 20 |
| MariaDB | 0.65 core | 1 |
| Examelite PHP-FPM | 0.28 core | 25 |
| PostgreSQL | 0.18 core | 23 |

Both civic extraction cron entries remain commented; no census worker process was found. Pausing civic collection therefore cannot remove the principal current consumers.

The latest 120 seconds of Pollmedia's access log contained 103 requests: 50 to `/india/constituency`, 40 to `/india/elections/lok-sabha`, and three to `/india/elections/assembly`. User-agent strings claimed GPTBot on 39 requests and Googlebot on 13, with other crawler/browser agents also present. Agent strings were not authenticated, and this sample does not establish an attack or attribute each request's CPU cost. It does show repeated dynamic-page traffic. No IP addresses, query strings or personal request details were exported.

## Import path

Preferred immediate option: coordinate a temporary pause of Examelite bulk Python/OCR processing using its supported worker/queue controls, allowing active jobs to finish safely. This affects a separate project and is outside the existing Pollmedia-only authorization; user approval was requested. Do not kill jobs, stop databases or change election processing. Inspect exact worker ownership/control and recovery before making a change. Preserve and restore the previous worker settings after the import window.

After that change, remeasure resources rather than assuming the gate is satisfied. Retain all existing civic admission thresholds. Capture scoped pre-import state, import the five-source package as drafts, review discrepancies, publish and verify eligible data; install/verify the preserved 1991 source tables when admitted. A partial release does not authorize new civic extraction.

If the user leaves Examelite running, keep imports queued and continue lightweight review. Do not bypass the load gate. Longer-term request caching and crawler traffic management merit separate review of the affected web routes; they are not authorized changes in this civic task. Adding swap would not resolve the measured CPU contention.

Available commands include `systemd-run`, `prlimit` and `ionice`; `cpulimit` was not found. Availability does not establish permission to change another project's limits, and no CPU quota was configured during this diagnosis.
