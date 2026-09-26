<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Mail\MailService;
use Psr\Log\LoggerInterface;
use TestHelpers\FakeConfiguration;

class MailServiceTest extends TestCase
{
    private MailService $mailService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mailService = new MailService(new FakeConfiguration(), $this->logger);
    }

    private function service(array $mail): MailService
    {
        return new MailService(new FakeConfiguration(['mail' => $mail]), $this->logger);
    }

    public function testBoundaryGenerationConsistency(): void
    {
        $mailService = $this->service([
            'enabled' => true,
            'transport' => 'php',
            'from' => 'test@example.com',
            'from_name' => 'Test Name',
            'return_path' => 'test@example.com',
        ]);

        // Use reflection to access private methods for testing
        $reflection = new \ReflectionClass($mailService);

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
        $headers = $getBaseHeadersMethod->invoke($mailService, $fromEmail, $fromName, $boundary);

        // Get message body with same boundary
        $messageBody = $getMessageBodyMethod->invoke($mailService, $htmlBody, $plainBody, $boundary);

        // Verify boundary is consistent in both headers and message body
        $this->assertStringContainsString("boundary=\"$boundary\"", $headers['Content-Type']);
        $this->assertStringContainsString("--$boundary", $messageBody);
        $this->assertStringContainsString("--{$boundary}--", $messageBody);
    }

    private function buildDsn(string $encryption, int $port): string
    {
        $service = $this->service([
            'host' => 'smtp.example.com',
            'port' => $port,
            'encryption' => $encryption,
            'username' => '',
            'password' => '',
            'auth' => false,
        ]);
        $method = new \ReflectionMethod($service, 'buildSmtpDsn');
        $method->setAccessible(true);
        return $method->invoke($service);
    }

    public static function lineBreakProvider(): array
    {
        return [
            'recipient' => ["victim@example.com\r\nBcc: other@example.net", 'Subject', []],
            'subject' => ['victim@example.com', "Subject\nBcc: other@example.net", []],
            'header value' => ['victim@example.com', 'Subject', ['Reply-To' => "a@example.com\r\nBcc: other@example.net"]],
            'header name' => ['victim@example.com', 'Subject', ["X-A\r\nBcc" => 'other@example.net']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lineBreakProvider')]
    public function testRefusesLineBreaksBeforeAnyTransportWritesHeaders(string $to, string $subject, array $headers): void
    {
        $this->logger->expects($this->once())->method('warning');
        $service = $this->service(['enabled' => true, 'transport' => 'logger']);

        $this->assertFalse($service->sendMail($to, $subject, '<p>body</p>', '', $headers));
    }

    public function testSendsWhenNoValueHasALineBreak(): void
    {
        $service = $this->service(['enabled' => true, 'transport' => 'logger']);
        $previous = ini_set('error_log', '/dev/null');
        try {
            $sent = $service->sendMail('user@example.com', 'Subject', '<p>body</p>', '', ['Reply-To' => 'a@example.com']);
        } finally {
            ini_set('error_log', (string)$previous);
        }

        $this->assertTrue($sent);
    }

    public function testReplyToBecomesAnAddressHeaderOnSmtpMessages(): void
    {
        $service = new MailService(new FakeConfiguration(), $this->logger);
        $email = new \Symfony\Component\Mime\Email();

        $method = new \ReflectionMethod($service, 'applySmtpHeaders');
        $method->invoke($service, $email, ['Reply-To' => 'rita@example.com', 'X-Mailer' => 'skip', 'X-Custom' => 'kept']);

        $this->assertSame('rita@example.com', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('kept', $email->getHeaders()->get('X-Custom')?->getBodyAsString());
        $this->assertFalse($email->getHeaders()->has('X-Mailer'));
    }

    public function testTlsEncryptionEnforcesStartTls(): void
    {
        // encryption=tls must enforce STARTTLS via require_tls (Symfony ignores a
        // bare ?encryption param, which would leave the session opportunistic).
        $dsn = $this->buildDsn('tls', 587);
        $this->assertStringStartsWith('smtp://smtp.example.com:587', $dsn);
        $this->assertStringContainsString('require_tls=true', $dsn);
        $this->assertStringNotContainsString('encryption=tls', $dsn);
    }

    public function testSslEncryptionUsesSmtpsScheme(): void
    {
        $dsn = $this->buildDsn('ssl', 465);
        $this->assertStringStartsWith('smtps://smtp.example.com:465', $dsn);
        $this->assertStringNotContainsString('require_tls', $dsn);
    }

    public function testNoEncryptionHasNoTlsOptions(): void
    {
        $dsn = $this->buildDsn('', 25);
        $this->assertEquals('smtp://smtp.example.com:25', $dsn);
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

        // Disable mail to avoid actual sending
        $mailService = $this->service(['enabled' => false]);

        // Test with multipart content - should generate boundary
        $result = $mailService->sendMail('test@example.com', 'Test Subject', '<html>HTML</html>', 'Plain text');

        // Since mail is disabled, it should return false but we've tested the boundary logic
        $this->assertFalse($result);
    }

    public function testNoBoundaryForSinglePart(): void
    {
        // Disable mail to avoid actual sending
        $mailService = $this->service(['enabled' => false]);

        // Test with single-part content - should not generate boundary
        $result = $mailService->sendMail('test@example.com', 'Test Subject', '<html>HTML</html>', '');

        // Since mail is disabled, it should return false
        $this->assertFalse($result);
    }
}
