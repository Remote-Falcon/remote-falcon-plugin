#!/usr/bin/env php
<?php
// Restart the Remote Falcon listener. Performs a real kill+respawn so the
// new PHP process picks up any plugin code changes left on disk by an
// upgrade. The previous flag-toggle approach only reloaded INI settings
// and could not re-import lib/ files that are require_once'd at startup.
//
// postStop.sh terminates via PID file (with pkill fallback for the
// transition case where an old listener was started before the PID
// scaffolding existed). postStart.sh launches a fresh PHP process and
// writes the new PID file.

$skipJSsettings = true;
include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";

$pluginDir = "/home/fpp/media/plugins/remote-falcon";
$pluginName = "remote-falcon";

// Ensure the listener will be running after this command — recovers from
// a previously-stopped state where the user had clicked "Stop Listener"
// and the enabled flag was set to false. The legacy restartingFlag is
// also cleared so the listener loop doesn't try to take over the restart.
WriteSettingToFile("remoteFalconListenerEnabled", urlencode("true"), $pluginName);
WriteSettingToFile("remoteFalconListenerRestarting", urlencode("false"), $pluginName);

// Serialize concurrent restart invocations with a PHP-side flock.
// Without this, rapid-fire UI clicks (or programmatic storms) race on
// postStart.sh's "check PID file → launch → write PID file" sequence
// and can spawn multiple listeners.
//
// PHP's flock() rather than flock(1): we LOCK_UN explicitly before
// returning instead of relying on descriptor lifetime. postStart.sh starts
// the listener through scripts/detach.sh, which closes inherited
// descriptors, so this lock fd no longer outlives the command.
$lockFh = @fopen("/tmp/rf-listener-lifecycle.lock", "c");
if ($lockFh !== false) {
    flock($lockFh, LOCK_EX);
}

exec($pluginDir . "/scripts/postStop.sh > /dev/null 2>&1");
exec($pluginDir . "/scripts/postStart.sh > /dev/null 2>&1");

if ($lockFh !== false) {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
}
?>
