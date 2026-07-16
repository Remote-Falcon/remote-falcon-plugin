<?php
// PHPUnit bootstrap: load Composer autoloader, the lib/ files under test,
// and the integration test scaffolding. None of these have FPP dependencies,
// safe to load anywhere PHP runs.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/listener_logic.php';
require_once __DIR__ . '/../lib/listener_http.php';
require_once __DIR__ . '/../lib/listener_log.php';
require_once __DIR__ . '/../lib/listener_actions.php';
require_once __DIR__ . '/../lib/sync_builder.php';
require_once __DIR__ . '/integration/MockServer.php';
require_once __DIR__ . '/integration/IntegrationTestCase.php';
