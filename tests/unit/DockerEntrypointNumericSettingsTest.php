<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A numeric PA_* value is emitted into settings.php as a bare PHP token, so a stray unit
 * or a leading zero (octal) used to turn every request into a parse error. Runs the real
 * generate_config() in bash and lints the file it writes.
 */
class DockerEntrypointNumericSettingsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-num-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * @param array<string, string> $env
     * @return array{0: string, 1: string}
     */
    private function generate(array $env): array
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is not available');
        }

        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($script);
        $this->assertSame(1, preg_match('/^main "\$@"$/m', $script), 'main invocation not found');
        $functions = $this->dir . '/functions.sh';
        file_put_contents($functions, (string) preg_replace('/^main "\$@"$/m', '', $script));

        $config = $this->dir . '/settings.php';
        $process = proc_open(
            [$bash, '-c', 'source "$1"; CONFIG_FILE="$2"; DB_TYPE=sqlite; DB_FILE=/tmp/pa-test.db; IS_ROOT=false; generate_config', 'gen', $functions, $config],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(['PATH' => (string) getenv('PATH'), 'PA_SESSION_KEY' => 'test-session-key'], $env)
        );
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $stderr);

        $lint = shell_exec('php -l ' . escapeshellarg($config) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', (string) $lint);

        return [(string) file_get_contents($config), $stderr];
    }

    public function testTypicalNumbersAreEmittedVerbatim(): void
    {
        [$config, $stderr] = $this->generate([
            'PA_DNS_TTL' => '3600',
            'PA_SESSION_TIMEOUT' => '0',
            'PA_DNS_TTL_REVERSE' => 'null',
            'PA_RECAPTCHA_V3_THRESHOLD' => '0.7',
        ]);

        $this->assertStringContainsString("'ttl' => 3600,", $config);
        $this->assertStringContainsString("'session_timeout' => 0,", $config);
        $this->assertStringContainsString("'ttl_reverse' => null,", $config);
        $this->assertStringContainsString("'v3_threshold' => 0.7,", $config);
        $this->assertStringNotContainsString('WARNING', $stderr);
    }

    public function testBadNumbersFallBackToTheDefaultAndWarn(): void
    {
        [$config, $stderr] = $this->generate([
            'PA_DNS_TTL' => '30m',
            'PA_SESSION_TIMEOUT' => '08',
            'PA_DNS_TTL_REVERSE' => 'abc',
            'PA_RECAPTCHA_V3_THRESHOLD' => '0.5.1',
            'PA_SMTP_PORT' => "587'; phpinfo(); //",
        ]);

        $this->assertStringContainsString("'ttl' => 86400,", $config);
        $this->assertStringContainsString("'session_timeout' => 1800,", $config);
        $this->assertStringContainsString("'ttl_reverse' => null,", $config);
        $this->assertStringContainsString("'v3_threshold' => 0.5,", $config);
        $this->assertStringContainsString("'port' => 587,", $config);
        $this->assertStringContainsString("PA_DNS_TTL='30m' is not a number", $stderr);
        $this->assertStringNotContainsString('phpinfo', $config);
    }
}
