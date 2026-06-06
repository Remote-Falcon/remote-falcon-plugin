# Changelog

All notable changes to the Remote Falcon FPP plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses date-based versioning (`YYYY.MM.DD.NN`).

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
