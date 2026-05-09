# Hardware tests

Tests that run against a real FPP Pi over SSH. Complement the unit
(`tests/`) and virtual-FPP integration tests (`tests/integration/`,
`tests/virtual-fpp/`) by validating real-hardware-only concerns:
listener stability over time, real-RF connectivity, lifecycle race
conditions, and FPP-side integration that the docker harness can't
fully reproduce.

These are **not run in CI** — they require a physical FPP and real
RF credentials (where applicable). Run them locally before cutting a
release.

## Prerequisites

- Passwordless SSH from the developer host to the Pi (`ssh fpp@<host>` works)
- `FPP_HOST` env var pointing at the Pi (default user is `fpp`; override with `FPP_USER`)
- A snapshot of the existing plugin tree taken automatically by `lib.sh` and restored at the end of each test (so the Pi returns to whatever state it was in)
- For the connectivity test only: the Pi must already have valid real RF settings on disk (real token, real `pluginsApiPath`)

## Tier 1 — quick hardware validation

| Script | What it checks | Runtime |
|---|---|---|
| `tier1-soak.sh` | Listener doesn't crash, leak memory, or explode the log under sustained load. Default 5 min, override with `SOAK_MINUTES`. | 5–15 min |
| `tier1-connectivity.sh` | cURL+TLS transport works against real `remotefalcon.com` infrastructure. 3 sequential read-only `/remotePreferences` calls; observes keep-alive speedup on warm calls. | <1 min |
| `tier1-restart-storm.sh` | 10 rapid restarts don't leave duplicate listeners or PID file corruption. Catches lifecycle race conditions. | <1 min |

Run all of Tier 1:

```bash
FPP_HOST=192.168.1.80 ./tests/hardware/tier1.sh
```

Or individually:

```bash
FPP_HOST=192.168.1.80 ./tests/hardware/tier1-soak.sh
FPP_HOST=192.168.1.80 SOAK_MINUTES=15 ./tests/hardware/tier1-soak.sh
FPP_HOST=192.168.1.80 ./tests/hardware/tier1-connectivity.sh
FPP_HOST=192.168.1.80 ./tests/hardware/tier1-restart-storm.sh
```

## Safety model

Every test:

1. Snapshots the existing plugin tree to `/tmp/rf-plugin-snap-*.tar.gz` and the settings file to `/tmp/rf-plugin-cfg-*.before`.
2. Stops the running listener cleanly (with `sudo` fallback for cross-user kill).
3. Replaces the plugin tree with the perf branch tip via `git clone`.
4. Configures **safe settings** — unreachable RF endpoint and empty token — so no RF traffic is generated. (Exception: `tier1-connectivity.sh` restores the user's real settings to make read-only calls against real RF.)
5. Runs the test.
6. Restores the snapshot, restarts the production listener, removes any
   logrotate config we added.

If a test fails or is interrupted mid-run, the snapshots stay in `/tmp/`
on the Pi for manual recovery. The most recent snapshot path is printed
near the start of every script.

## Adding a new test

Source `lib.sh` for shared helpers (`pi`, `pi_snapshot`, `pi_install_branch`,
`pi_seed_safe_settings`, `pi_start_listener_as_fpp`, `pi_listener_pid`,
`pi_listener_alive`, `pi_restore`, plus `ok`/`fail`/`section`/`summarize`).

Pattern:

```bash
#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
source "$HERE/lib.sh"

section "Tier N #M — what this checks"

SNAP=$(pi_snapshot)
pi_stop_listener > /dev/null
pi_install_branch perf/listener-tightening > /dev/null
pi_seed_safe_settings > /dev/null
pi_start_listener_as_fpp > /dev/null

# ... your assertions, calling ok / fail ...

pi_restore "$SNAP" > /dev/null
summarize
```
