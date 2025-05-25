<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LogHandlerInterface;

class PasswordResetLoggingTest extends TestCase
{
    private $mockHandler;
    private Logger $logger;
    private array $capturedLogs = [];

    protected function setUp(): void
    {
        $this->capturedLogs = [];
        $this->mockHandler = $this->createMock(LogHandlerInterface::class);

        // Capture all log calls for inspection
        $this->mockHandler->method('handle')
            ->willReturnCallback(function ($data) {
                $this->capturedLogs[] = $data;
            });

        $this->logger = new Logger($this->mockHandler, 'info');
    }

    public function testPasswordResetTokenAccessedLogging(): void
    {
        $this->logger->info('Valid password reset token accessed', [
            'user_id' => 123,
            'email' => 'test@example.com',
            'ip' => '192.168.1.1',
            'timestamp' => '2025-05-26 15:57:54'
        ]);

        $this->assertCount(1, $this->capturedLogs);
        $log = $this->capturedLogs[0];

        $this->assertEquals('INFO', $log['level']);
        $this->assertStringContainsString('Valid password reset token accessed', $log['message']);

        // Verify context is included
        $this->assertStringContainsString('"user_id":123', $log['message']);
        $this->assertStringContainsString('"email":"test@example.com"', $log['message']);
        $this->assertStringContainsString('"ip":"192.168.1.1"', $log['message']);
        $this->assertStringContainsString('"timestamp":"2025-05-26 15:57:54"', $log['message']);
    }

    public function testPasswordResetRateLimitLogging(): void
    {
        $this->logger->warning('Password reset rate limit exceeded', [
            'email' => 'attacker@example.com',
            'ip' => '10.0.0.1',
            'timestamp' => '2025-05-26 16:00:00'
        ]);

        $this->assertCount(1, $this->capturedLogs);
        $log = $this->capturedLogs[0];

        $this->assertEquals('WARNING', $log['level']);
        $this->assertStringContainsString('Password reset rate limit exceeded', $log['message']);
        $this->assertStringContainsString('[{"email":"attacker@example.com","ip":"10.0.0.1","timestamp":"2025-05-26 16:00:00"}]', $log['message']);
    }

    public function testPasswordResetCompletedLogging(): void
    {
        $this->logger->info('Password reset completed via web interface', [
            'user_id' => 456,
            'email' => 'user@example.com',
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'browser' => [
                'name' => 'Safari',
                'version' => '15.0',
                'platform' => 'macOS'
            ],
            'timestamp' => '2025-05-26 16:15:00'
        ]);

        $this->assertCount(1, $this->capturedLogs);
        $log = $this->capturedLogs[0];

        // Parse the JSON from the message
        $messageWithoutContext = 'Password reset completed via web interface';
        $jsonStart = strpos($log['message'], ' [');
        $this->assertNotFalse($jsonStart);

        $contextJson = substr($log['message'], $jsonStart + 2, -1);
        $contextData = json_decode($contextJson, true);

        $this->assertIsArray($contextData);
        $this->assertEquals(456, $contextData['user_id']);
        $this->assertEquals('user@example.com', $contextData['email']);
        $this->assertEquals('192.168.1.100', $contextData['ip']);
        $this->assertIsArray($contextData['browser']);
        $this->assertEquals('Safari', $contextData['browser']['name']);
    }

    public function testPasswordResetFailedLogging(): void
    {
        $this->logger->info('Password reset failed - password policy violation', [
            'user_id' => 789,
            'email' => 'weak@example.com',
            'policy_errors' => [
                'Password must be at least 6 characters long',
                'Password must contain at least one uppercase letter',
                'Password must contain at least one number'
            ],
            'ip' => '192.168.1.50',
            'timestamp' => '2025-05-26 16:30:00'
        ]);

        $this->assertCount(1, $this->capturedLogs);
        $log = $this->capturedLogs[0];

        // Verify policy errors are included in context
        $this->assertStringContainsString('"policy_errors":[', $log['message']);
        $this->assertStringContainsString('"Password must be at least 6 characters long"', $log['message']);
        $this->assertStringContainsString('"Password must contain at least one uppercase letter"', $log['message']);
        $this->assertStringContainsString('"Password must contain at least one number"', $log['message']);
    }

    public function testInvalidTokenLogging(): void
    {
        $this->logger->warning('Invalid password reset token presented', [
            'ip' => '192.168.1.200',
            'user_agent' => 'curl/7.64.1',
            'browser' => [
                'name' => 'Unknown',
                'version' => null,
                'is_bot' => true
            ],
            'token_length' => 64,
            'timestamp' => '2025-05-26 17:00:00'
        ]);

        $this->assertCount(1, $this->capturedLogs);
        $log = $this->capturedLogs[0];

        $this->assertEquals('WARNING', $log['level']);

        // Parse context to verify structure
        $jsonStart = strpos($log['message'], ' [');
        $contextJson = substr($log['message'], $jsonStart + 2, -1);
        $contextData = json_decode($contextJson, true);

        $this->assertEquals(64, $contextData['token_length']);
        $this->assertTrue($contextData['browser']['is_bot']);
        $this->assertNull($contextData['browser']['version']);
    }

    public function testMultipleLogEntriesWithContext(): void
    {
        // Simulate a full password reset flow
        $this->logger->info('Password reset requested', [
            'email' => 'user@example.com',
            'ip' => '192.168.1.1'
        ]);

        $this->logger->info('Password reset email sent', [
            'email' => 'user@example.com',
            'token_expires_at' => '2025-05-26 17:00:00'
        ]);

        $this->logger->info('Valid password reset token accessed', [
            'user_id' => 123,
            'email' => 'user@example.com'
        ]);

        $this->logger->info('Password reset completed successfully', [
            'user_id' => 123,
            'email' => 'user@example.com'
        ]);

        $this->assertCount(4, $this->capturedLogs);

        // Verify each log has its context
        foreach ($this->capturedLogs as $index => $log) {
            $this->assertStringContainsString(' [', $log['message'], "Log entry $index should have context");
            $this->assertStringContainsString('user@example.com', $log['message'], "Log entry $index should contain email");
        }
    }
}
