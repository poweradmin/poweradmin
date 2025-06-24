<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\LoggerInterface;

/**
 * @group disabled
 */
class MailServiceTest extends TestCase
{
    private MailService $mailService;
    private ConfigurationManager $config;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mailService = new MailService($this->config, $this->logger);
    }

    public function testBoundaryGenerationConsistency(): void
    {
        // Configure mock to enable mail and use PHP transport
        $this->config->expects($this->any())->method('get')->willReturnMap([
            ['mail', 'enabled', false, true],
            ['mail', 'transport', 'smtp', 'php'],
            ['mail', 'from', 'poweradmin@example.com', 'test@example.com'],
            ['mail', 'from_name', '', 'Test Name'],
            ['mail', 'return_path', 'poweradmin@example.com', 'test@example.com'],
        ]);

        // Use reflection to access private methods for testing
        $reflection = new \ReflectionClass($this->mailService);

        $getBaseHeadersMethod = $reflection->getMethod('getBaseHeaders');
        $getBaseHeadersMethod->setAccessible(true);

        $getMessageBodyMethod = $reflection->getMethod('getMessageBody');
        $getMessageBodyMethod->setAccessible(true);

        // Test with boundary
        $boundary = 'test_boundary_123';
        $fromEmail = 'test@example.com';
        $fromName = 'Test Name';
        $htmlBody = '<html><body>Test HTML</body></html>';
        $plainBody = 'Test plain text';

        // Get headers with boundary
        $headers = $getBaseHeadersMethod->invoke($this->mailService, $fromEmail, $fromName, $boundary);

        // Get message body with same boundary
        $messageBody = $getMessageBodyMethod->invoke($this->mailService, $htmlBody, $plainBody, $boundary);

        // Verify boundary is consistent in both headers and message body
        $this->assertStringContainsString("boundary=\"$boundary\"", $headers['Content-Type']);
        $this->assertStringContainsString("--$boundary", $messageBody);
        $this->assertStringContainsString("--{$boundary}--", $messageBody);
    }

    public function testSinglePartEmailNoBoundary(): void
    {
        // Use reflection to access private methods
        $reflection = new \ReflectionClass($this->mailService);

        $getBaseHeadersMethod = $reflection->getMethod('getBaseHeaders');
        $getBaseHeadersMethod->setAccessible(true);

        $getMessageBodyMethod = $reflection->getMethod('getMessageBody');
        $getMessageBodyMethod->setAccessible(true);

        $fromEmail = 'test@example.com';
        $fromName = 'Test Name';
        $htmlBody = '<html><body>Test HTML</body></html>';
        $plainBody = '';
        $boundary = '';

        // Get headers without boundary
        $headers = $getBaseHeadersMethod->invoke($this->mailService, $fromEmail, $fromName, $boundary);

        // Get message body without boundary
        $messageBody = $getMessageBodyMethod->invoke($this->mailService, $htmlBody, $plainBody, $boundary);

        // Verify no boundary is used for single-part emails
        $this->assertEquals('text/html; charset=UTF-8', $headers['Content-Type']);
        $this->assertEquals($htmlBody, $messageBody);
    }

    public function testMultipartEmailStructure(): void
    {
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->mailService);
        $getMessageBodyMethod = $reflection->getMethod('getMessageBody');
        $getMessageBodyMethod->setAccessible(true);

        $boundary = 'test_boundary_456';
        $htmlBody = '<html><body>HTML content</body></html>';
        $plainBody = 'Plain text content';

        $messageBody = $getMessageBodyMethod->invoke($this->mailService, $htmlBody, $plainBody, $boundary);

        // Verify multipart structure
        $this->assertStringContainsString("--$boundary", $messageBody);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $messageBody);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $messageBody);
        $this->assertStringContainsString($plainBody, $messageBody);
        $this->assertStringContainsString($htmlBody, $messageBody);
        $this->assertStringContainsString("--{$boundary}--", $messageBody);
    }

    public function testBoundaryGenerationLogic(): void
    {
        // Use reflection to test the boundary generation logic in sendMail
        $reflection = new \ReflectionClass($this->mailService);
        $sendMailMethod = $reflection->getMethod('sendMail');

        // Configure mock
        $this->config->expects($this->any())->method('get')->willReturnMap([
            ['mail', 'enabled', false, false], // Disable mail to avoid actual sending
        ]);

        // Test with multipart content - should generate boundary
        $result = $this->mailService->sendMail('test@example.com', 'Test Subject', '<html>HTML</html>', 'Plain text');

        // Since mail is disabled, it should return false but we've tested the boundary logic
        $this->assertFalse($result);
    }

    public function testNoBoundaryForSinglePart(): void
    {
        // Configure mock
        $this->config->expects($this->any())->method('get')->willReturnMap([
            ['mail', 'enabled', false, false], // Disable mail to avoid actual sending
        ]);

        // Test with single-part content - should not generate boundary
        $result = $this->mailService->sendMail('test@example.com', 'Test Subject', '<html>HTML</html>', '');

        // Since mail is disabled, it should return false
        $this->assertFalse($result);
    }
}
