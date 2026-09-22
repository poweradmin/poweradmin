<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Functional;

use PHPUnit\Framework\TestCase;

/**
 * Drives dynamic_update.php in a subprocess: the dyndns2 text protocol answers
 * a rejected request with a status word and nothing else, and it opens the
 * database before it looks at the request at all.
 */
class DynamicUpdateEntryPointTest extends TestCase
{
    public function testARequestWithoutAUserAgentIsRejectedBeforeTheDatabaseIsNeeded(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runDynamicUpdate([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("badagent\n", $stdout);
        $this->assertSame('', $stderr);
    }

    public function testARequestWithoutAUsernameIsRejectedAsBadAuth(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runDynamicUpdate(['HTTP_USER_AGENT' => 'curl/8']);

        $this->assertSame(0, $exitCode);
        $this->assertSame("badauth\n", $stdout);
        $this->assertSame('', $stderr);
    }

    public function testARequestWithoutAHostnameIsRejectedAsNotFqdn(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runDynamicUpdate(['HTTP_USER_AGENT' => 'curl/8', 'PHP_AUTH_USER' => 'bob']);

        $this->assertSame(0, $exitCode);
        $this->assertSame("notfqdn\n", $stdout);
        $this->assertSame('', $stderr);
    }

    /**
     * The connection is opened eagerly, so an unreachable database fails the
     * request even when the request itself would have been rejected without one.
     */
    public function testAnUnreachableDatabaseFailsBeforeTheRequestIsRead(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runDynamicUpdate([], 'broken-database-settings.php');

        $this->assertSame(255, $exitCode);
        $this->assertStringNotContainsString('badagent', $stdout);
        $this->assertStringContainsString('Database connection failed', $stdout . $stderr);
    }

    /**
     * @param array<string, string> $environment Extra CGI variables (user agent, basic auth)
     * @return array{0: int, 1: string, 2: string} Exit code, stdout, stderr
     */
    private function runDynamicUpdate(array $environment, string $settingsFixture = 'halting-settings.php'): array
    {
        $repositoryRoot = dirname(__DIR__, 2);

        $process = proc_open(
            [PHP_BINARY, $repositoryRoot . '/dynamic_update.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repositoryRoot,
            $environment + [
                'PATH' => getenv('PATH'),
                'PA_CONFIG_PATH' => __DIR__ . '/fixtures/' . $settingsFixture,
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/dynamic_update.php',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '80',
                'REMOTE_ADDR' => '192.0.2.10',
            ]
        );

        $this->assertIsResource($process, 'Failed to start the dynamic update subprocess');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
