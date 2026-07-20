# Changelog

All notable changes to the Remote Falcon FPP plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses date-based versioning (`YYYY.MM.DD.NN`).

## [2026.07.16.01] - 2026-07-20

### Added
- **Auto Sync** (#13): opt-in automatic sync of playlist changes to Remote Falcon with a
  30-second quiet-window debounce, run out-of-process so the listener tick never blocks.
- **Set Active Viewer Page** command (#68): switch the active viewer page from the FPP UI,
  scheduler, or presets, with a live page-name dropdown.
- FPP 10 support (#173): new `pluginInfo.json` versions entry so the plugin registers as
  installable on FPP 10 (which no longer treats `maxFPPVersion: "0"` as "all future
  majors"); docker test matrix gains a permanent `10.x-master` slot.

### Changed
- Sequence-sync payload is built server-side in one shared builder used by both the UI
  and command paths (#158); browser-to-RF calls now go through the `plugin.php` JSON
  proxy, fixing CSP failures on self-hosted installs.
- **Update Remote Playlist** command dispatches the sync out-of-process instead of running
  it inline in the fppd callback.

### Fixed
- Settings changes are picked up reliably: the listener now clears PHP's stat cache before
  mtime checks (stale `filemtime()` made config edits invisible until restart) and closes
  a same-second mtime race that caused an infinite soft-restart loop.
- `set_active_viewer_page.php` shipped without the executable bit, so FPPD silently
  no-opped the command; the bit is fixed and `preStart.sh` now heals `commands/*.php`
  permissions on every FPP start.
- Set Active Viewer Page treats any 2xx response as success.

## [2026.06.15.01] - 2026-06-15

Promotes the `perf/listener-tightening` reliability pass to a stable release.

### Added
- Comprehensive automated test coverage: PHPUnit unit + integration suites for the
  listener logic/HTTP/action layers, a Docker FPP-version matrix, and Tier 1–3
  hardware-validation harnesses (restart storms, settings persistence, overnight soak/stress).

### Changed
- Listener internals refactored into focused `lib/` modules (`listener_logic`,
  `listener_http`, `listener_actions`, `listener_log`); the main loop now delegates to
  pure, unit-tested helpers.
- Performance: outbound RF API calls reuse a keep-alive cURL connection; FPP playlist
  details are cached with a 60s TTL; the settings INI is re-parsed only when its mtime
  changes; per-tick verbose log noise was cut and logrotate is installed.
- Raised the UI's client-side sync guard for `remotefalcon.com` from 200 to 500 items to
  match the plugins-api sequence limit.

### Fixed
- Self-hosted Remote Falcon: Test Connectivity and version reporting now work behind CSP.
- Restart reliability: the Restart button actually restarts the listener; cross-user kills
  fall back to sudo; concurrent restarts are serialized via flock.
- Suppressed FPP `common.php` HTML/CLI-context pollution in the listener log.

## [2026.06.06.01] - 2026-06-06

### Fixed
- `getNextSequence` now scans forward past pause (and other non-sequence)
  entries, wrapping at the end of the playlist, instead of giving up when the
  immediately-following entry has no `sequenceName`. This keeps the dashboard's
  `NEXT_PLAYLIST` populated when a pause sits between sequences.

## [2026.05.15.01] - 2026-05-15

### Added
- Heartbeat: the listener now posts to `/fppHeartbeat` every 30s independent
  of show state, so the Remote Falcon dashboard can render plugin liveness
  and outage windows. First tick fires immediately on startup so the "alive"
  signal lands without waiting the full interval.

## [2026.01.02.01] and earlier

See the [commit log](https://github.com/Remote-Falcon/remote-falcon-plugin/commits/master)
for changes prior to this changelog being kept.
