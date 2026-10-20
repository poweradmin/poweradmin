<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\PasswordEncryptionService;

class PasswordEncryptionServiceTest extends TestCase
{
    private const SESSION_KEY = 'my-secret-key';

    private PasswordEncryptionService $passwordEncryptionService;

    protected function setUp(): void
    {
        $this->passwordEncryptionService = new PasswordEncryptionService(self::SESSION_KEY);
    }

    public function testEncryptionAndDecryption()
    {
        $password = 'my-secret-password';

        $encryptedPassword = $this->passwordEncryptionService->encrypt($password);
        $decryptedPassword = $this->passwordEncryptionService->decrypt($encryptedPassword);

        $this->assertEquals($password, $decryptedPassword);
    }

    public function testEncryptionProducesDifferentResults()
    {
        $password = 'my-secret-password';

        $encryptedPassword1 = $this->passwordEncryptionService->encrypt($password);
        $encryptedPassword2 = $this->passwordEncryptionService->encrypt($password);

        $this->assertNotEquals($encryptedPassword1, $encryptedPassword2);
    }

    public function testEncryptionWithEmptyPassword()
    {
        $password = '';

        $encryptedPassword = $this->passwordEncryptionService->encrypt($password);

        $this->assertEquals('', $encryptedPassword);
    }

    public function testDecryptionWithEmptyPassword()
    {
        $password = '';

        $decryptedPassword = $this->passwordEncryptionService->decrypt($password);

        $this->assertEquals('', $decryptedPassword);
    }

    public function testZeroStringPasswordIsStillEncrypted()
    {
        $encryptedPassword = $this->passwordEncryptionService->encrypt('0');

        $this->assertNotSame('', $encryptedPassword);
    }

    public function testZeroStringPasswordRoundTrips()
    {
        $encryptedPassword = $this->passwordEncryptionService->encrypt('0');

        $this->assertSame('0', $this->passwordEncryptionService->decrypt($encryptedPassword));
    }

    public function testDecryptionWithMalformedInputReturnsEmptyString()
    {
        $this->assertSame('', $this->passwordEncryptionService->decrypt('0'));
        $this->assertSame('', $this->passwordEncryptionService->decrypt('not-encrypted'));
    }

    public function testDecryptionWithAMalformedIvReturnsEmptyStringWithoutAWarning()
    {
        $ciphertext = explode(':', $this->passwordEncryptionService->encrypt('secret'), 2)[0];
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });

        try {
            $shortIv = $this->passwordEncryptionService->decrypt($ciphertext . ':' . base64_encode('short'));
            $notBase64 = $this->passwordEncryptionService->decrypt($ciphertext . ':***');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('', $shortIv);
        $this->assertSame('', $notBase64);
        $this->assertSame([], $warnings);
    }
}
