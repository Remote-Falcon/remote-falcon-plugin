# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The Remote Falcon Plugin is an FPP (Falcon Player) plugin that enables remote control of Christmas light shows through the Remote Falcon web application (https://remotefalcon.com). The plugin runs as a background listener service on FPP hardware, communicating with both the FPP local API and the Remote Falcon backend API to enable viewer interaction with light shows.

## Technology Stack

- **Language**: PHP 7+
- **Platform**: FPP (Falcon Player) - Linux-based show controller
- **Frontend**: Vanilla JavaScript, HTML, CSS
- **APIs**: FPP REST API (local), Remote Falcon Plugins API (remote)

## Repository Structure

- `remote_falcon_listener.php` - Core background service that polls FPP status and Remote Falcon API
- `remote_falcon_ui.html` - Configuration UI displayed in FPP web interface
- `menu.inc` - FPP menu integration (registers the plugin's pages under FPP's content/help menus)
- `pluginInfo.json` - FPP plugin manifest with version compatibility and metadata
- `commands/` - PHP scripts for FPP commands (toggle viewer control, restart listener, etc.)
- `commands/descriptions.json` - Command definitions for FPP integration
- `commands/_lib.php` - Shared helpers (`rf_load_settings`, `rf_request`) used by command scripts
- `js/` - JavaScript files for UI functionality
- `css/` - Styling for the plugin UI
- `scripts/` - Installation and lifecycle hooks (`fpp_install.sh`, `fpp_uninstall.sh`, `preStart.sh`, `postStart.sh`, `preStop.sh`, `postStop.sh`)
- `help/help.php` - In-app help documentation linked from FPP's Help menu

## Architecture

### Listener Service (remote_falcon_listener.php)

The listener runs as a long-lived PHP process backgrounded by `scripts/postStart.sh`. The script writes the PID to `/home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid` so `postStop.sh` and `fpp_uninstall.sh` can shut it down cleanly. FPP itself does not supervise the daemon.

1. **Initialization**: Reads plugin configuration from `/opt/fpp/media/config/plugin.remote-falcon`
2. **Main Loop**: Continuously polls FPP status and Remote Falcon API
3. **Two Modes**:
   - **Non-Interrupt Mode**: Inserts requested/voted sequences after the current sequence when time remaining < fetch time
   - **Interrupt Mode**: Immediately inserts requested/voted sequences, interrupting the schedule

### Key Configuration Settings

Stored in FPP config file and managed through the UI:
- `remoteToken` - Show token from Remote Falcon account
- `remotePlaylist` - FPP playlist containing sequences for viewer control
- `interruptSchedule` - Boolean to enable interrupt mode
- `requestFetchTime` - Seconds before end of sequence to fetch next request (1-5, default 3)
- `additionalWaitTime` - Extra wait after fetching (0-5, default 0)
- `fppStatusCheckTime` - Polling interval for FPP status (0.5-fetch time, default 1)
- `pluginsApiPath` - Remote Falcon API endpoint (default: https://remotefalcon.com/remote-falcon-plugins-api)
- `verboseLogging` - Enable detailed debug logging

### API Integration

#### FPP Local API (http://127.0.0.1/api)
- `GET /api/system/status` - Get current FPP status (playing sequence, time remaining, etc.)
- `GET /api/playlist/{name}` - Get playlist details
- `GET /api/command/Insert Playlist Immediate/{playlist}/{start}/{end}` - Insert and play immediately
- `GET /api/command/Insert Playlist After Current/{playlist}/{start}/{end}` - Queue after current

#### Remote Falcon Plugins API
All requests include `remotetoken` header:
- `GET /remotePreferences` - Get viewer control mode (voting/jukebox)
- `GET /highestVotedPlaylist` - Get winning sequence from voting
- `GET /nextPlaylistInQueue?updateQueue=true` - Get and dequeue next jukebox request
- `POST /updateWhatsPlaying` - Update currently playing sequence
- `POST /updateNextScheduledSequence` - Update next scheduled sequence
- `POST /fppHeartbeat` - Send keepalive heartbeat

### Version Support

The plugin supports FPP versions 2.0 through 9.0+ with different branches:
- FPP 2.0-4.9.9: `master-4` branch
- FPP 5.0-7.99: `master` branch
- FPP 8.0-8.99: `master` branch
- FPP 9.0+: `master` branch

## Development Workflow

### Testing strategy

Most listener logic is pure code that can be tested locally without FPP. Real hardware is the FINAL gate, not the only place tests live.

**Layer 1 — PHPUnit unit tests (run anywhere, <2s).** For any pure function — sequence selection, dedup logic, cache behavior, settings parsing, value clamping. Functions in `lib/` MUST have unit tests in `tests/`. Run with `vendor/bin/phpunit` after `composer install`.

**Layer 2 — Integration tests with mock servers (run anywhere, ~30s).** For end-to-end listener flow using mock FPP and RF backends. Add a test when changing the listener's loop or HTTP behavior. (Layer 2 harness lands in a follow-up branch.)

**Layer 3 — Browser smoke (manual, optional).** UI changes (`remote_falcon_ui.html`, `js/`) can be sanity-checked locally with a mock backend before pushing.

**Layer 4 — Real-hardware validation (release gate).** Required before merging changes to master that touch the listener, lifecycle scripts, or commands/. Workflow:
1. Make changes locally on a feature branch.
2. Layers 1+2 must be green in CI.
3. Push the branch, install on a real FPP via the Plugin Manager.
4. Monitor `/home/fpp/media/logs/remote-falcon-listener.log` and run the smoke checklist.
5. Merge to master.

CI runs Layers 1+2 on every push (`.github/workflows/test.yml`).

### Local development

Plugin files live at `/opt/fpp/media/plugins/remote-falcon/` on FPP systems. Direct SSH access allows for rapid testing without git commits.

To run tests locally:
```bash
composer install     # one-time, creates vendor/ (gitignored)
vendor/bin/phpunit
```

The `vendor/` directory is **never committed**. PHPUnit is a dev-only dependency; it never ships to FPP installs. CI verifies this via the `vendor-check` job. See `composer.json` for the dependency list and `phpunit.xml` for test configuration.

### Pure-logic extraction

Functions that take inputs and produce outputs (no I/O, no global mutation, no logging) live in `lib/listener_logic.php` and are unit-tested. The listener (`remote_falcon_listener.php`) `require_once`s the lib and calls the helpers from its orchestration code. When adding new pure logic, write it in `lib/` first and test it before wiring it up in the listener.

### Logs

The listener logs to `/home/fpp/media/logs/remote-falcon-listener.log` with timestamps. `scripts/postStart.sh` also redirects the daemon's stdout/stderr to the same file, so fatal PHP errors that bypass `logEntry` still get captured. Enable verbose logging for detailed API call timing and execution flow.

## Deployment & Updates

FPP's plugin manager controls install and upgrade:

- **Install**: FPP runs `git clone --single-branch --branch <branch> <srcURL>` into `/home/fpp/media/plugins/remote-falcon/`, then executes `scripts/fpp_install.sh` once.
- **Upgrade**: FPP runs the equivalent of `git pull` against the configured branch. **`fpp_install.sh` is NOT re-run on upgrade** — anything that needs to happen on every code change must live in `postStart.sh` or in the plugin code itself.
- **Uninstall**: FPP runs `scripts/fpp_uninstall.sh` (which kills the listener via PID file and removes the CSP entry) and then deletes the plugin directory.

The lifecycle scripts (`preStart.sh`, `postStart.sh`, `preStop.sh`, `postStop.sh`) run on plugin start/stop. FPP does not supervise the listener daemon — the plugin owns its own process via the PID file.

## Important Patterns

### Configuration Management

Settings are stored in FPP's INI-style config file and accessed via:
```php
$pluginConfigFile = $settings['configDirectory'] . "/plugin.remote-falcon";
$pluginSettings = parse_ini_file($pluginConfigFile);
$value = urldecode($pluginSettings['key']);
```

Writing settings:
```php
WriteSettingToFile("key", urlencode($value), "remote-falcon");
```

### Logging

Two logging functions available:
```php
logEntry($data);           // Always logs
logEntry_verbose($data);   // Only logs when verboseLogging enabled
```

### Listener Control

The listener checks two control flags each iteration:
- `remoteFalconListenerEnabled` - Set to "false" to stop listener
- `remoteFalconListenerRestarting` - Set to "true" to reload configuration

Commands set these flags to control the listener without killing the process.

### Execution Flow

Non-interrupt mode:
1. Poll FPP status every `fppStatusCheckTime` seconds
2. When `seconds_remaining < requestFetchTime`, fetch next request/vote
3. Insert sequence after current
4. Sleep for `requestFetchTime + additionalWaitTime`

Interrupt mode:
1. Poll FPP status every `fppStatusCheckTime` seconds
2. If not playing remote playlist, fetch request/vote and play immediately
3. If playing remote playlist, fall back to non-interrupt mode

## Plugin Installation Hooks

- `fpp_install.sh` - Adds required Content-Security-Policy for remotefalcon.com, marks for FPP reboot
- `preStart.sh` - Empty (reserved for future use)
- `postStart.sh` - Runs after plugin starts
- `preStop.sh` - Runs before plugin stops
- `postStop.sh` - Runs after plugin stops
- `fpp_uninstall.sh` - Cleanup on plugin removal

## Commands

FPP commands callable from playlists or UI (defined in `commands/descriptions.json`):
- Turn interrupt schedule on/off
- Turn viewer control on/off
- Turn managed PSA on/off
- Purge queue/reset votes
- Restart listener
- Stop listener
- Update remote playlist

Each command is a PHP script in `commands/` that modifies plugin settings.
