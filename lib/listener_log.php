<?php
// Logging helpers for the Remote Falcon listener. Extracted from
// remote_falcon_listener.php so the action layer (lib/listener_actions.php)
// can use them without requiring the listener's main loop file.
//
// Both functions read the global $logFile (string path to the log file) and
// $GLOBALS['verboseLogging'] (bool). When $logFile is unset or unwritable,
// log lines fall back to error_log so messages are not silently dropped.

if (!function_exists('logEntry')) {

    function logEntry($data) {
        global $logFile;

        $logWrite = @fopen($logFile, "a");
        if ($logWrite === false) {
            error_log("Remote Falcon listener cannot open log file: " . $logFile . " | Message: " . $data);
            return;
        }

        fwrite($logWrite, date('Y-m-d h:i:s A', time()) . ": " . $data . "\n");
        fclose($logWrite);
    }

    function logEntry_verbose($data) {
        if (!isset($GLOBALS['verboseLogging']) || $GLOBALS['verboseLogging'] !== true) {
            return;
        }

        global $logFile;

        $logWrite = @fopen($logFile, "a");
        if ($logWrite === false) {
            error_log("Remote Falcon listener cannot open log file: " . $logFile . " | Message: " . $data);
            return;
        }

        fwrite($logWrite, date('Y-m-d h:i:s A', time()) . ": " . $data . "\n");
        fclose($logWrite);
    }
}
