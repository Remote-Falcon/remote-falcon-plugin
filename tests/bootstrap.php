<?php
// PHPUnit bootstrap: load Composer autoloader and the lib/ files under test.
// The lib/ files are pure logic (no FPP dependencies), safe to load anywhere.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/listener_logic.php';
