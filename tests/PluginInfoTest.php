<?php

use PHPUnit\Framework\TestCase;

/**
 * Guards pluginInfo.json against FPP Plugin Manager registration regressions.
 *
 * FPP 10 changed SelectPluginVersionIndices (www/plugins.php): an entry with
 * maxFPPVersion "0" whose minFPPVersion major differs from the running major
 * is capped at (curMajor - 1).999 and demoted to "untested". "0" no longer
 * means "all future majors" — each new FPP major needs a versions[] entry
 * whose min-major matches it. Issue #173: the 9.0 -> 0 entry left the plugin
 * uninstallable on FPP 10.
 *
 * selectCompatible() below replicates that FPP 10 selection logic so the
 * assertions fail the moment a supported FPP version loses coverage.
 */
class PluginInfoTest extends TestCase {

    private function pluginInfo(): array {
        $raw = file_get_contents(__DIR__ . '/../pluginInfo.json');
        $data = json_decode($raw, true);
        $this->assertIsArray($data, 'pluginInfo.json must be valid JSON');
        return $data;
    }

    private static function isUnset($m): bool {
        return $m === '0' || $m === '0.0' || $m === '' || $m === null;
    }

    /** Numeric segment-wise version compare, mirroring FPP's CompareFPPVersions. */
    private static function compareVersions(string $a, string $b): int {
        $as = array_map('intval', explode('.', $a));
        $bs = array_map('intval', explode('.', $b));
        for ($i = 0; $i < max(count($as), count($bs)); $i++) {
            $av = $as[$i] ?? 0;
            $bv = $bs[$i] ?? 0;
            if ($av !== $bv) {
                return $av <=> $bv;
            }
        }
        return 0;
    }

    /**
     * Replica of FPP 10's SelectPluginVersionIndices for a given FPP version.
     * Returns the selected versions[] entry, or null if none is compatible.
     */
    private static function selectCompatible(array $versions, string $fppVersion): ?array {
        $curMajor = (int) explode('.', $fppVersion)[0];
        $compatible = null;
        foreach ($versions as $v) {
            $effMax = $v['maxFPPVersion'];
            if (self::isUnset($effMax)) {
                $minMajor = (int) explode('.', (string) $v['minFPPVersion'])[0];
                if ($minMajor !== $curMajor) {
                    $effMax = ($curMajor - 1) . '.999';
                }
            }
            $minOk = self::compareVersions((string) $v['minFPPVersion'], $fppVersion) <= 0;
            $maxOk = self::isUnset($effMax) || self::compareVersions($effMax, $fppVersion) > 0;
            if ($minOk && $maxOk) {
                $compatible = $v;
            }
        }
        return $compatible;
    }

    public function testEverySupportedFppVersionSelectsACompatibleEntry(): void {
        $versions = $this->pluginInfo()['versions'];
        foreach (['5.0', '6.3', '7.5', '8.2', '9.0', '9.4', '10.0', '10.1'] as $fpp) {
            $entry = self::selectCompatible($versions, $fpp);
            $this->assertNotNull($entry, "FPP $fpp has no compatible pluginInfo.json entry (plugin would show as untested/uninstallable)");
            $this->assertSame('master', $entry['branch'], "FPP $fpp should install from master");
        }
    }

    public function testCurrentFppMajorHasAMatchingMinMajorEntry(): void {
        // The unbounded entry only survives FPP's untested-cap when its
        // min-major equals the running major. When a new FPP major ships,
        // this test's version list above must grow AND pluginInfo.json needs
        // a matching entry — this assertion names the entry that must exist.
        $versions = $this->pluginInfo()['versions'];
        $unbounded = array_values(array_filter($versions, fn($v) => self::isUnset($v['maxFPPVersion'])));
        $this->assertCount(1, $unbounded, 'Exactly one unbounded (maxFPPVersion "0") entry expected');
        $this->assertSame('10.0', $unbounded[0]['minFPPVersion']);
    }
}
