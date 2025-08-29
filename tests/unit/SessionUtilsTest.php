<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PoweradminInstall\SessionUtils;

class SessionUtilsTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure we have a clean session state for each test
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
    }

    protected function tearDown(): void
    {
        // Clean up session after each test
        $_SESSION = [];
    }

    public function testClearMessages(): void
    {
        // Set up various session data
        $_SESSION['messages'] = [
            'install' => [
                ['type' => 'error', 'content' => 'Installation error'],
                ['type' => 'info', 'content' => 'Installation info']
            ],
            'system' => [
                ['type' => 'error', 'content' => 'System error']
            ]
        ];

        // Add some non-message session data that should NOT be cleared
        $_SESSION['form_data'] = [
            'token123' => ['data' => ['field1' => 'value1'], 'expires' => time() + 300]
        ];
        $_SESSION['install_token'] = 'test-token';
        $_SESSION['user_id'] = 123;
        $_SESSION['authenticated'] = true;

        // Execute the method
        SessionUtils::clearMessages();

        // Assert that only messages were cleared
        /** @var array<string, mixed> $session */
        $session = $_SESSION;
        $this->assertArrayNotHasKey('messages', $session);

        // Assert that non-message session data was preserved
        $this->assertArrayHasKey('form_data', $session);
        $this->assertArrayHasKey('install_token', $session);
        $this->assertArrayHasKey('user_id', $session);
        $this->assertArrayHasKey('authenticated', $session);
        $this->assertEquals('test-token', $_SESSION['install_token']);
        $this->assertEquals(123, $_SESSION['user_id']);
        $this->assertTrue($_SESSION['authenticated']);
    }

    public function testClearMessagesWithPartialData(): void
    {
        // Set up only messages and other session data
        $_SESSION['messages'] = [
            'install' => [['type' => 'error', 'content' => 'Test error']]
        ];
        $_SESSION['install_token'] = 'test-token';
        $_SESSION['user_id'] = 456;

        SessionUtils::clearMessages();

        // Assert only messages were cleared
        /** @var array<string, mixed> $session */
        $session = $_SESSION;
        $this->assertArrayNotHasKey('messages', $session);

        // Assert other data was preserved
        $this->assertArrayHasKey('install_token', $session);
        $this->assertArrayHasKey('user_id', $session);
        $this->assertEquals('test-token', $_SESSION['install_token']);
        $this->assertEquals(456, $_SESSION['user_id']);
    }

    public function testClearMessagesWithEmptySession(): void
    {
        // Start with session containing no messages
        $_SESSION = ['user_data' => 'should_remain'];

        // Should not throw errors when there are no messages to clear
        SessionUtils::clearMessages();

        // Non-message data should remain
        $this->assertArrayHasKey('user_data', $_SESSION);
        $this->assertEquals('should_remain', $_SESSION['user_data']);
    }
}
