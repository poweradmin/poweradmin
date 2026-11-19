<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A stray unit or leading zero in a numeric PA_* variable (PA_SESSION_TIMEOUT=30m) used to be
 * written raw into settings.php, which then failed to parse on every request.
 */
class DockerEntrypointNumericConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (trim((string)shell_exec('command -v bash')) === '') {
            $this->markTestSkipped('bash is not available');
        }

        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($script);
        $withoutMain = preg_replace('/^main "\$@"\s*$/m', '', $script, -1, $count);
        $this->assertSame(1, $count, 'the trailing main call was not found');

        $this->dir = sys_get_temp_dir() . '/pa-entrypoint-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/entry.sh', $withoutMain);
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
     * @return string the generated settings.php
     */
    private function generate(array $env): string
    {
        $assignments = '';
        foreach ($env as $name => $value) {
            $assignments .= $name . '=' . escapeshellarg($value) . ' ';
        }

        $cmd = sprintf(
            'cd %1$s && env -i PATH="$PATH" HOME=%1$s %2$sCONFIG_FILE=%3$s DB_TYPE=sqlite bash -c %4$s 2>&1',
            escapeshellarg($this->dir),
            $assignments,
            escapeshellarg($this->dir . '/settings.php'),
            escapeshellarg('source ' . $this->dir . '/entry.sh; generate_config')
        );
        exec($cmd, $output, $rc);
        $this->assertSame(0, $rc, implode("\n", $output));

        $config = file_get_contents($this->dir . '/settings.php');
        $this->assertNotFalse($config);
        return $config;
    }

    private function assertParses(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($this->dir . '/settings.php') . ' 2>&1', $output, $rc);
        $this->assertSame(0, $rc, implode("\n", $output));
    }

    public function testTypicalNumbersAreEmittedAsGiven(): void
    {
        $config = $this->generate(['PA_SESSION_TIMEOUT' => '3600', 'PA_SMTP_PORT' => '25', 'PA_RECAPTCHA_V3_THRESHOLD' => '0.7']);

        $this->assertParses();
        $this->assertStringContainsString("'session_timeout' => 3600,", $config);
        $this->assertStringContainsString("'port' => 25,", $config);
        $this->assertStringContainsString("'v3_threshold' => 0.7,", $config);
    }

    public function testBadNumbersFallBackToTheDefault(): void
    {
        $config = $this->generate([
            'PA_SESSION_TIMEOUT' => '30m',
            'PA_DNS_TTL' => '0800',
            'PA_SMTP_PORT' => "25');phpinfo();//",
            'PA_DNS_TTL_REVERSE' => 'abc',
        ]);

        $this->assertParses();
        $this->assertStringContainsString("'session_timeout' => 1800,", $config);
        $this->assertStringContainsString("'ttl' => 86400,", $config);
        $this->assertStringContainsString("'port' => 587,", $config);
        $this->assertStringContainsString("'ttl_reverse' => null,", $config);
        $this->assertStringNotContainsString('phpinfo', $config);
    }
}
