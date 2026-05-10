# Remote Falcon Plugin — Beta Testing (perf branch)

Thanks for helping test the next release. This branch (`perf/listener-tightening`) is a performance + reliability pass on the FPP listener; it has been validated against five FPP major versions in Docker, an 8-hour soak on a real Pi 3, and the full hardware integration suite.

This guide walks you through:

1. Confirming your FPP version is supported
2. Backing up your current state (just in case, the install path does not touch your settings)
3. Installing the beta
4. Verifying it came up
5. Reverting to the stable release

If anything goes wrong, the **revert** section restores you to the exact branch you started on.

---

## 1. What's in the beta

You should not need to change anything in your existing config. Behavior changes you may notice:

- **Quieter listener log.** The per-tick "Getting FPP Status" line was removed at verbose level. With idle time the log barely moves.
- **5-second poll cadence when FPP is idle.** When no playlist is playing, the listener sleeps 5 seconds between FPP polls instead of using your `fppStatusCheckTime`. As soon as a sequence starts the listener returns to your configured cadence.
- **Faster RF API calls.** Outbound RF calls now reuse a single TLS connection (cURL keep-alive), shaving roughly half the per-call latency.
- **Cached playlist details.** Playlist metadata is cached for 60 s so repeated polls during a show don't re-fetch the full playlist.
- **Settings file is no longer re-parsed every tick.** It is reloaded only when its modification time changes.
- **Restart Listener no longer races itself.** Rapid clicks of the Restart button can no longer spawn multiple listeners.
- **Logrotate config installed.** Listener log is rotated automatically; you should not see it grow unbounded any more.

None of the above changes the way you configure the plugin. Your existing settings continue to work.

---

## 2. Compatibility — read before installing

The beta is only published for branches that match your FPP major version. Pick the right path below.

| Your FPP version       | Supported in this beta? | Notes                                                         |
|------------------------|-------------------------|---------------------------------------------------------------|
| FPP 9.x (any)          | YES                     | Primary test target. Validated on FPP 9.4 + FPP 9.x-master.   |
| FPP 8.x                | YES                     | Validated on FPP 8.4.                                         |
| FPP 7.x                | YES                     | Validated on FPP 7.5.                                         |
| FPP 6.x                | YES                     | Validated on FPP 6.x-master.                                  |
| FPP 5.x                | YES                     | Validated on FPP 5.5.                                         |
| FPP 2.0 – 4.9.9        | **NO — do not install** | The plugin's `master-4` branch has not been updated yet. Stay on the released `master-4` plugin. |

### How to confirm your FPP version

From any machine on the same network as your FPP host (replace `<FPP_HOST>` with the IP or hostname):

```bash
curl -s http://<FPP_HOST>/api/system/status | grep -oE '"version":"[^"]*"'
```

Or, in the FPP web UI: **Status / Control → System Info**.

If that returns `4.x.x` or lower, **stop here** — this beta is not for you. Wait for the `master-4` branch to be updated separately.

### Tested-on summary

| FPP version       | Pulled docker tag         | Result   |
|-------------------|---------------------------|----------|
| FPP 5.5           | `falconchristmas/fpp:5.5`           | PASS  |
| FPP 6.x-master    | `falconchristmas/fpp:6.x-master`    | PASS  |
| FPP 7.5           | `falconchristmas/fpp:7.5`           | PASS  |
| FPP 8.4           | `falconchristmas/fpp:8.4`           | PASS  |
| FPP 9.4 (Pi 3, real hardware) | image v2025-11           | PASS, 8 h soak clean |

---

## 3. What you need before starting

- **SSH access** to your FPP host as the `fpp` user (the FPP default).
- About 5 minutes for the swap.
- An idle FPP — do not run this during a live show.

You do not need to change your Remote Falcon account, token, or playlists. Settings are stored in `/home/fpp/media/config/plugin.remote-falcon`, which lives **outside** the plugin tree and is preserved across uninstall/install.

---

## 4. Install the beta

All commands run on the FPP host as `fpp` (over SSH).

```bash
# Step A — Belt-and-suspenders backup of your settings.
# The install path does NOT touch this file, but a backup costs nothing.
sudo cp /home/fpp/media/config/plugin.remote-falcon ~/rf-config-backup.ini

# Step B — Stop and remove the currently-installed plugin.
sudo /home/fpp/media/plugins/remote-falcon/scripts/postStop.sh
sudo rm -rf /home/fpp/media/plugins/remote-falcon

# Step C — Clone the beta branch and run the install hook.
cd /home/fpp/media/plugins
sudo git clone --branch perf/listener-tightening --single-branch \
    https://github.com/Remote-Falcon/remote-falcon-plugin.git remote-falcon
sudo chown -R fpp:fpp remote-falcon
sudo -u fpp env FPPDIR=/opt/fpp \
    /home/fpp/media/plugins/remote-falcon/scripts/fpp_install.sh

# Step D — Start the listener.
sudo -u fpp /home/fpp/media/plugins/remote-falcon/scripts/postStart.sh
```

That's it. Your settings file (`/home/fpp/media/config/plugin.remote-falcon`) was never touched, so your token, playlist name, intervals, etc. all carry over.

---

## 5. Verify it came up

Run these on the FPP host. Each one should return a value or look healthy.

```bash
# 5.1 — A non-empty PID file means the listener is launched and tracked.
cat /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid

# 5.2 — That PID should still be alive.
ps -p $(cat /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid) \
    -o pid,user,etime,cmd

# 5.3 — Log should show the new startup banner with version 2026.01.02.01
#       and your settings echoed back.
tail -20 /home/fpp/media/logs/remote-falcon-listener.log

# 5.4 — Plugin commands should appear in FPP's command list.
curl -s http://127.0.0.1/api/commands | grep -F 'Remote Falcon'
```

If all four look good, the beta is live. Use Remote Falcon as you normally would and watch for any unexpected behavior over the next day or two.

---

## 6. Revert to the released plugin

The revert path depends on your FPP version. Pick the matching block.

### FPP 5.x, 6.x, 7.x, 8.x, 9.x — revert to `master`

```bash
# Stop and remove the beta
sudo /home/fpp/media/plugins/remote-falcon/scripts/postStop.sh
sudo rm -rf /home/fpp/media/plugins/remote-falcon

# Reinstall the released plugin (master branch)
cd /home/fpp/media/plugins
sudo git clone --branch master --single-branch \
    https://github.com/Remote-Falcon/remote-falcon-plugin.git remote-falcon
sudo chown -R fpp:fpp remote-falcon
sudo -u fpp env FPPDIR=/opt/fpp \
    /home/fpp/media/plugins/remote-falcon/scripts/fpp_install.sh
sudo -u fpp /home/fpp/media/plugins/remote-falcon/scripts/postStart.sh
```

### FPP 2.0 – 4.9.9 — revert to `master-4`

If you are on FPP 4.x or below, you should not have installed this beta in the first place (see section 2). If you did and need to recover, use the `master-4` branch below.

```bash
sudo /home/fpp/media/plugins/remote-falcon/scripts/postStop.sh
sudo rm -rf /home/fpp/media/plugins/remote-falcon

cd /home/fpp/media/plugins
sudo git clone --branch master-4 --single-branch \
    https://github.com/Remote-Falcon/remote-falcon-plugin.git remote-falcon
sudo chown -R fpp:fpp remote-falcon
sudo -u fpp env FPPDIR=/opt/fpp \
    /home/fpp/media/plugins/remote-falcon/scripts/fpp_install.sh
sudo -u fpp /home/fpp/media/plugins/remote-falcon/scripts/postStart.sh
```

After either revert, run the four checks from section 5 again. The startup banner version will go back to whatever the released plugin reports.

---

## 7. If something goes wrong

### Settings somehow disappeared

Restore from the backup you made in step A:

```bash
sudo cp ~/rf-config-backup.ini /home/fpp/media/config/plugin.remote-falcon
sudo chown fpp:fpp /home/fpp/media/config/plugin.remote-falcon
sudo chmod 664 /home/fpp/media/config/plugin.remote-falcon
```

Then restart the listener:

```bash
sudo /home/fpp/media/plugins/remote-falcon/scripts/postStop.sh
sudo -u fpp /home/fpp/media/plugins/remote-falcon/scripts/postStart.sh
```

### Listener won't start after install

Check the log for the failure reason:

```bash
sudo tail -50 /home/fpp/media/logs/remote-falcon-listener.log
```

Common causes:

- **PHP version mismatch** — the plugin supports PHP 7.4 through 8.5. If your FPP image ships a stranger version, capture the output of `php --version` and include it in the bug report below.
- **Permissions** — the plugin tree should be owned by `fpp:fpp`. If `ls -l /home/fpp/media/plugins/remote-falcon` shows root, run `sudo chown -R fpp:fpp /home/fpp/media/plugins/remote-falcon`.
- **Stale PID file** — if a previous listener was killed badly, remove the PID file and restart: `sudo rm /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid` and rerun `postStart.sh`.

### You want to bail out completely

The revert path in section 6 is always safe. If even that fails, you can manually clean up and reinstall via FPP's Plugin Manager UI:

```bash
# Nuclear option: stop everything, remove the plugin, restore settings,
# then use FPP's Plugin Manager (Content Setup → Plugin Manager) to
# reinstall Remote Falcon normally.
sudo /home/fpp/media/plugins/remote-falcon/scripts/postStop.sh 2>/dev/null
sudo rm -rf /home/fpp/media/plugins/remote-falcon
sudo cp ~/rf-config-backup.ini /home/fpp/media/config/plugin.remote-falcon
```

---

## 8. Reporting issues

Please open an issue at: https://github.com/Remote-Falcon/remote-falcon-issue-tracker/issues

Include:

- Your FPP version (`curl -s http://127.0.0.1/api/system/status | grep version` works)
- Hardware (Pi 3 / Pi 4 / Pi 5 / BBB / x86)
- The last 200 lines of `/home/fpp/media/logs/remote-falcon-listener.log`
- A short description of what you were doing when the issue showed up

The more specific the timeline (e.g. "during a sequence interrupt"), the faster we can reproduce it.

Thanks again for helping test.
