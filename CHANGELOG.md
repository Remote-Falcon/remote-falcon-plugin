# Changelog

All notable changes to the Remote Falcon FPP plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses date-based versioning (`YYYY.MM.DD.NN`).

## [2026.09.14.01] - 2026-09-14

"Next scheduled" fixes for playlists with repeated sequences and for shows restarted
without restarting the listener.

### Added
- `iconURL` in `pluginInfo.json`, so FPP's plugin list shows the Remote Falcon icon
  (reached `master` just after 2026.08.08.01 was tagged).

### Fixed
- "Next scheduled" is correct on playlists that repeat a sequence. The listener now uses
  FPP's playlist position instead of the first entry with a matching name, so a later
  occurrence no longer reports what follows the first one. Playlists are read with
  sub-playlists merged so positions line up; random playlists, and scheduled ranges that
  play only part of a playlist, keep the previous name-based lookup.
- "Next scheduled" no longer stays blank for the whole first song after stopping a show
  and starting it again without restarting the listener.

## [2026.08.08.01] - 2026-08-08

FPP 10 readiness release, driven by FPP's plugin-check scan
(FalconChristmas/fpp-data#207) and the new PLUGIN_GUIDELINES.md.

### Added
- 256x256 `icon.png` so the Plugin Manager shows the RF mark instead of initials.
- GitHub issue-template config routing bug reports to the central issue tracker.
- `tier1-log-migration.sh` hardware test covering the log rename migration.
- CI now tests PHP 8.4 (the FPP 10 runtime).

### Changed
- **Listener log renamed to `plugin-remote-falcon.log`** (FPP's `plugin-<repoName>.log`
  convention, picked up by FPP's log viewer and Support Zip). Existing logs are migrated
  automatically on first start after upgrade.
- Log rotation is now left to FPP (which rotates all plugin logs itself); the
  plugin-owned logrotate config is removed, including the legacy copy under
  `/etc/logrotate.d/` on upgraded installs.
- Lifecycle scripts resolve FPP's media/logs directories via `${FPPDIR}/scripts/common`
  instead of hardcoding `/home/fpp/media`, and no longer use `sudo` (hooks run as root).
- Plugin UI is theme-aware: status text, sync dialog, and log tail follow FPP's
  light/dark theme via Bootstrap CSS variables; sync dialog is responsive at phone widths.
- Single Help menu entry (FPP allows one entry per menu area); the remotefalcon.com link
  lives inside the Help page.

### Fixed
- Uninstall now requests an fppd restart so the plugin's registered commands don't
  linger as ghosts until the next unrelated restart.
- The listener refuses to run under a web SAPI, so it can no longer be started through
  FPP's `plugin.php` and pin an Apache worker.
- Removed the commented-out donation link and its CSS (FPP plugins may not reference
  donation services).

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
