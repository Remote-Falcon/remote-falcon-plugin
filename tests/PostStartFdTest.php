<?php

use PHPUnit\Framework\TestCase;

/**
 * postStart.sh must launch the listener without the caller's open file
 * descriptors.
 *
 * The "Restart Listener" FPP command runs as a child of fppd, so the shell
 * that reaches postStart.sh holds every fppd descriptor without CLOEXEC:
 * the DDP (4048) and E1.31 (5568) sockets, the command socket, fppd.log. A
 * listener launched from there kept them for its whole life. FPP's default
 * "Restart FPPD" re-execs fppd in place without running plugin hooks, so
 * the new fppd found 4048 still bound by the listener and exit(1)'d on
 * "DDP bind failed: Address already in use". fppd stayed down until a
 * reboot killed the listener.
 */
class PostStartFdTest extends TestCase {

    // Arbitrary high number so the fake listener's own opens can't land on it.
    private const MARKER_FD = 47;

    private string $mediaDir;

    protected function setUp(): void {
        // postStart.sh also does `rm -f /etc/logrotate.d/remote-falcon`, which
        // only succeeds as root. Don't touch a real install's /etc from a test.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('runs the real postStart.sh, which edits /etc as root');
        }
        $this->mediaDir = sys_get_temp_dir() . '/rf-poststart-' . bin2hex(random_bytes(4));
        mkdir($this->mediaDir . '/plugins/remote-falcon/scripts', 0777, true);
        mkdir($this->mediaDir . '/logs');
        copy(__DIR__ . '/../scripts/detach.sh', $this->mediaDir . '/plugins/remote-falcon/scripts/detach.sh');
    }

    protected function tearDown(): void {
        if (isset($this->mediaDir)) {
            exec('rm -rf ' . escapeshellarg($this->mediaDir));
        }
    }

    public function testListenerDoesNotInheritCallerDescriptors(): void {
        $pluginDir = $this->mediaDir . '/plugins/remote-falcon';
        $result = $this->mediaDir . '/result.txt';
        $fd = self::MARKER_FD;

        // Stand-in listener: report whether the marker fd reached it and its
        // PID, then exit. Written to a temp name and renamed so the poll below
        // never reads a half-written file.
        file_put_contents($pluginDir . '/remote_falcon_listener.php',
            "<?php\n"
            . "\$h = @fopen('php://fd/$fd', 'w');\n"
            . "\$out = " . var_export($result, true) . ";\n"
            . "file_put_contents(\$out . '.tmp', (\$h ? 'inherited' : 'closed') . ' ' . getmypid());\n"
            . "rename(\$out . '.tmp', \$out);\n");

        $marker = $this->mediaDir . '/marker';
        $cmd = sprintf(
            'exec %d>%s; FPPDIR=%s MEDIADIR=%s LOGDIR=%s RF_PHP=%s bash %s',
            $fd,
            escapeshellarg($marker),
            escapeshellarg($this->mediaDir . '/no-fpp'),
            escapeshellarg($this->mediaDir),
            escapeshellarg($this->mediaDir . '/logs'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../scripts/postStart.sh')
        );
        exec('bash -c ' . escapeshellarg($cmd), $out, $rc);
        $this->assertSame(0, $rc, 'postStart.sh failed');

        $deadline = microtime(true) + 10;
        while (!file_exists($result) && microtime(true) < $deadline) {
            usleep(50000);
        }
        $this->assertFileExists($result, 'stand-in listener never ran');
        [$fdState, $pid] = explode(' ', file_get_contents($result));

        $this->assertSame('closed', $fdState,
            "listener inherited the caller's fd $fd (fppd's sockets in the field)");
        // postStop.sh/preStart.sh signal the PID in this file. If the launcher
        // stops exec'ing into the listener, it records a wrapper's PID and the
        // listener is orphaned on every restart.
        $this->assertSame($pid, trim(file_get_contents($pluginDir . '/remote_falcon_listener.pid')),
            'pidfile does not hold the listener PID');
    }
}
