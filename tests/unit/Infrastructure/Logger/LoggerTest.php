<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LogHandlerInterface;

class LoggerTest extends TestCase
{
    private $mockHandler;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->mockHandler = $this->createMock(LogHandlerInterface::class);
        $this->logger = new Logger($this->mockHandler, 'debug');
    }

    public function testLogWithContextData(): void
    {
        $context = [
            'user_id' => 123,
            'email' => 'test@example.com',
            'ip' => '192.168.1.1',
            'timestamp' => '2025-05-26 15:57:54'
        ];

        $expectedContext = ' [{"user_id":123,"email":"test@example.com","ip":"192.168.1.1","timestamp":"2025-05-26 15:57:54"}]';

        $this->mockHandler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function ($data) use ($expectedContext) {
                return str_ends_with($data['message'], $expectedContext);
            }));

        $this->logger->info('Test message', $context);
    }

    public function testLogWithEmptyContext(): void
    {
        $this->mockHandler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function ($data) {
                // Should not have context JSON appended
                return $data['message'] === 'Test message without context';
            }));

        $this->logger->info('Test message without context', []);
    }

    public function testLogWithClassnameContext(): void
    {
        $context = [
            'classname' => 'TestClass',
            'user_id' => 456,
            'action' => 'login'
        ];

        $expectedContext = ' [{"user_id":456,"action":"login"}]';

        $this->mockHandler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function ($data) use ($expectedContext) {
                // Classname should be in separate field, not in context JSON
                return $data['classname'] === '[TestClass]'
                    && str_ends_with($data['message'], $expectedContext);
            }));

        $this->logger->info('Test with classname', $context);
    }

    public function testLogWithOnlyClassnameContext(): void
    {
        $context = [
            'classname' => 'OnlyClassname'
        ];

        $this->mockHandler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function ($data) {
                // Should not have context JSON when only classname is present
                return $data['classname'] === '[OnlyClassname]'
                    && $data['message'] === 'Test message';
            }));

        $this->logger->info('Test message', $context);
    }

    public function testLogWithUnicodeContext(): void
    {
        $context = [
            'name' => 'José García',
            'message' => 'Hello 世界',
            'emoji' => '😀'
        ];

        $expectedContext = ' [{"name":"José García","message":"Hello 世界","emoji":"😀"}]';

        $this->mockHandler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function ($data) use ($expectedContext) {
                return str_ends_with($data['message'], $expectedContext);
            }));

        $this->logger->info('Unicode test', $context);
    }

    public function testLogWithNestedArrayContext(): void
    {
        $context = [
            'user' => [
                'id' => 789,
                'name' => 'John Doe'
            ],
            'simple' => 'value'
        ];

        $expectedContext = ' [{"user":{"id":789,"name":"John Doe"},"simple":"value"}]';

        $this->mockHandler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function ($data) use ($expectedContext) {
                return str_ends_with($data['message'], $expectedContext);
            }));

        $this->logger->info('Nested array test', $context);
    }

    public function testLogWithUrlContext(): void
    {
        $context = [
            'url' => 'https://example.com/path?param=value',
            'redirect' => 'https://example.com/test'
        ];

        // URLs should not have slashes escaped
        $expectedContext = ' [{"url":"https://example.com/path?param=value","redirect":"https://example.com/test"}]';

        $this->mockHandler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function ($data) use ($expectedContext) {
                return str_ends_with($data['message'], $expectedContext);
            }));

        $this->logger->info('URL test', $context);
    }

    public function testLogLevelFiltering(): void
    {
        // Create logger with 'warning' level
        $warningLogger = new Logger($this->mockHandler, 'warning');

        // Debug and info should not be logged
        $this->mockHandler->expects($this->never())
            ->method('handle');

        $warningLogger->debug('Debug message', ['level' => 'debug']);
        $warningLogger->info('Info message', ['level' => 'info']);
    }

    public function testLogLevelAllowed(): void
    {
        // Create logger with 'warning' level
        $warningLogger = new Logger($this->mockHandler, 'warning');

        // Warning should be logged
        $this->mockHandler->expects($this->once())
            ->method('handle');

        $warningLogger->warning('Warning message', ['level' => 'warning']);
    }

    public function testInterpolation(): void
    {
        $context = [
            'user' => 'John',
            'action' => 'login',
            'time' => '10:30'
        ];

        $expectedMessage = 'User John performed login at 10:30 [{"user":"John","action":"login","time":"10:30"}]';

        $this->mockHandler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function ($data) use ($expectedMessage) {
                return $data['message'] === $expectedMessage;
            }));

        $this->logger->info('User {user} performed {action} at {time}', $context);
    }

    public function testPasswordResetLogging(): void
    {
        // Test actual password reset context format
        $context = [
            'user_id' => 123,
            'email' => 'user@example.com',
            'ip' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'browser' => ['name' => 'Chrome', 'version' => '91.0'],
            'timestamp' => '2025-05-26 15:57:54'
        ];

        $this->mockHandler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function ($data) {
                $message = $data['message'];
                // Check that context is properly JSON encoded
                return str_contains($message, '"user_id":123')
                    && str_contains($message, '"email":"user@example.com"')
                    && str_contains($message, '"ip":"192.168.1.1"')
                    && str_contains($message, '"browser":{"name":"Chrome","version":"91.0"}');
            }));

        $this->logger->info('Password reset completed via web interface', $context);
    }
}
