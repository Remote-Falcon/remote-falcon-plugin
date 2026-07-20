<?php

use PHPUnit\Framework\TestCase;

/**
 * Guards on the FPP command scripts in commands/.
 *
 * FPPD execs these files directly (Plugins.cpp), so a script without the
 * executable bit fails with "Permission denied" in fppd.log while the FPP
 * UI still reports the command as "complete" — a silent no-op in the field.
 * set_active_viewer_page.php shipped as 644 in the 2026.07.16 cycle and
 * every invocation via FPPD (UI, scheduler, presets) did nothing.
 */
class CommandScriptsTest extends TestCase {

    private const NON_EXECUTABLE = ['_lib.php', 'descriptions.json'];

    private function commandScripts(): array {
        $dir = __DIR__ . '/../commands';
        $scripts = [];
        foreach (glob($dir . '/*.php') as $path) {
            if (!in_array(basename($path), self::NON_EXECUTABLE, true)) {
                $scripts[] = $path;
            }
        }
        return $scripts;
    }

    public function testEveryCommandScriptIsExecutable(): void {
        $scripts = $this->commandScripts();
        $this->assertNotEmpty($scripts);
        foreach ($scripts as $path) {
            $this->assertTrue(
                is_executable($path),
                basename($path) . " is not executable — FPPD will fail to exec it with Permission denied"
            );
        }
    }

    public function testEveryCommandScriptHasShebang(): void {
        foreach ($this->commandScripts() as $path) {
            $firstLine = strtok((string) file_get_contents($path), "\n");
            $this->assertStringStartsWith(
                '#!',
                (string) $firstLine,
                basename($path) . " has no shebang — FPPD execs the file directly"
            );
        }
    }

    public function testEveryScriptInDescriptionsExistsAndViceVersa(): void {
        $descriptions = json_decode((string) file_get_contents(__DIR__ . '/../commands/descriptions.json'), true);
        $declared = array_map(fn($c) => $c['script'], $descriptions);
        $onDisk = array_map('basename', $this->commandScripts());
        sort($declared);
        sort($onDisk);
        $this->assertSame($declared, $onDisk,
            'commands/descriptions.json and commands/*.php disagree');
    }
}
