<?php

namespace unit;

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
}
