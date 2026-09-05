<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Logger\NativeLogHandler;

class NativeLogHandlerTest extends TestCase
{
    private string $logFile;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'pa-log');
        $this->previousErrorLog = (string)ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->logFile);
    }

    public function testNewlinesInContextValuesAreFlattened(): void
    {
        $handler = new NativeLogHandler();
        $handler->handle([
            'timestamp' => '2026-09-05T00:00:00+00:00',
            'level' => 'warning',
            'classname' => 'SqlAuthenticator',
            'message' => "No user found: admin\n2026-09-05 [info]: forged line\r\n",
        ]);

        $lines = array_values(array_filter(explode("\n", (string)file_get_contents($this->logFile))));

        $this->assertCount(1, $lines, 'Every log entry must stay on one line.');
        $this->assertStringContainsString('No user found: admin 2026-09-05 [info]: forged line', $lines[0]);
    }
}
