<?php

/**
 * Spawns a `php -S` child process that runs router.php as the request handler.
 * Tests configure routes by calling setRoute() before issuing requests, then
 * inspect getRecordings() afterward to assert on the listener's HTTP behavior.
 *
 * Each instance gets its own port and its own pair of temp files (config and
 * recordings) so multiple mock servers can run in parallel without collisions.
 */
final class MockServer {
    private ?int $port = null;
    /** @var resource|null */
    private $process = null;
    private string $configPath;
    private string $recordingsPath;
    private string $name;

    public function __construct(string $name = 'mock') {
        $this->name = $name;
        $uid = $name . '-' . uniqid('', true);
        $this->configPath = sys_get_temp_dir() . "/rf-{$uid}.config.json";
        $this->recordingsPath = sys_get_temp_dir() . "/rf-{$uid}.recordings.json";
        file_put_contents($this->configPath, '{}');
        file_put_contents($this->recordingsPath, '[]');
    }

    public function start(): int {
        $this->port = $this->findFreePort();
        $router = realpath(__DIR__ . '/router.php');
        $cmd = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $this->port,
            escapeshellarg($router)
        );
        $env = array_merge(
            $this->inheritedEnv(),
            [
                'RF_MOCK_CONFIG' => $this->configPath,
                'RF_MOCK_RECORDINGS' => $this->recordingsPath,
            ]
        );
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];
        $this->process = proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($this->process)) {
            throw new RuntimeException("[{$this->name}] failed to start mock server");
        }
        $this->waitForReady();
        return $this->port;
    }

    public function stop(): void {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 15);  // SIGTERM
            $deadline = microtime(true) + 1.0;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($this->process);
                if (!$status['running']) break;
                usleep(20000);
            }
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process, 9);  // SIGKILL
            }
            proc_close($this->process);
            $this->process = null;
        }
        @unlink($this->configPath);
        @unlink($this->recordingsPath);
    }

    public function setRoute(string $path, array $config): void {
        $cfg = json_decode(file_get_contents($this->configPath), true) ?: [];
        $cfg[$path] = $config;
        file_put_contents($this->configPath, json_encode($cfg));
    }

    public function clearRecordings(): void {
        file_put_contents($this->recordingsPath, '[]');
    }

    public function getRecordings(): array {
        $raw = file_get_contents($this->recordingsPath);
        return json_decode($raw, true) ?: [];
    }

    public function getBaseUrl(): string {
        if ($this->port === null) {
            throw new RuntimeException('Server not started');
        }
        return "http://127.0.0.1:{$this->port}";
    }

    private function findFreePort(): int {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$sock) {
            throw new RuntimeException("Failed to find free port: $errstr");
        }
        $name = stream_socket_get_name($sock, false);
        stream_socket_shutdown($sock, STREAM_SHUT_RDWR);
        $parts = explode(':', $name);
        return (int) end($parts);
    }

    private function waitForReady(): void {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $sock = @stream_socket_client(
                "tcp://127.0.0.1:{$this->port}",
                $errno,
                $errstr,
                0.1
            );
            if ($sock) {
                fclose($sock);
                return;
            }
            usleep(50000);
        }
        throw new RuntimeException("[{$this->name}] mock server failed to become ready on port {$this->port}");
    }

    private function inheritedEnv(): array {
        // Pass through the minimum env vars `php -S` needs to find binaries.
        $keys = ['PATH', 'HOME', 'USER', 'TMPDIR', 'LANG', 'LC_ALL'];
        $env = [];
        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $env[$k] = $v;
            }
        }
        return $env;
    }
}
