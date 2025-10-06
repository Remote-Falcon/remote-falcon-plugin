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
- `api.php` - Defines REST endpoints exposed by the plugin
- `pluginInfo.json` - FPP plugin manifest with version compatibility and metadata
- `commands/` - PHP scripts for FPP commands (toggle viewer control, restart listener, etc.)
- `commands/descriptions.json` - Command definitions for FPP integration
- `js/` - JavaScript files for UI functionality
- `css/` - Styling for the plugin UI
- `scripts/` - Installation and lifecycle hooks (install, start, stop)
- `help/` - Help documentation

## Architecture

### Listener Service (remote_falcon_listener.php)

The listener runs as a continuous PHP process managed by FPP:

1. **Initialization**: Reads plugin configuration from `/opt/fpp/media/config/plugin.remote-falcon`
2. **Main Loop**: Continuously polls FPP status and Remote Falcon API
3. **Two Modes**:
   - **Non-Interrupt Mode**: Inserts requested/voted sequences after the current sequence when time remaining < fetch time
   - **Interrupt Mode**: Immediately inserts requested/voted sequences, interrupting the schedule
4. **Heartbeat**: Sends periodic heartbeat to Remote Falcon API every 15 seconds

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

### Testing Changes

Since this plugin runs on FPP hardware, testing typically requires:
1. Make changes locally
2. Commit and push to GitHub
3. Update plugin on FPP through the FPP web interface (Status/Control > Plugin Manager)
4. Monitor logs at `/home/fpp/media/logs/remote_falcon_listener.log`

### Local Development

The plugin files are typically located at `/opt/fpp/media/plugins/remote-falcon/` on FPP systems. Direct SSH access allows for rapid testing without git commits.

### Logs

The listener logs to `/home/fpp/media/logs/remote_falcon_listener.log` with timestamps. Enable verbose logging for detailed API call timing and execution flow.

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
