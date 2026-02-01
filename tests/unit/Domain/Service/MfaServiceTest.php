<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Repository\UserMfaRepositoryInterface;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use RuntimeException;

#[CoversClass(MfaService::class)]
class MfaServiceTest extends TestCase
{
    private MfaService $service;
    private UserMfaRepositoryInterface&MockObject $userMfaRepository;
    private ConfigurationManager&MockObject $configManager;
    private MailService&MockObject $mailService;

    private string $originalErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        // Suppress error_log output during tests
        $this->originalErrorLog = ini_get('error_log') ?: '';
        ini_set('error_log', '/dev/null');

        $this->userMfaRepository = $this->createMock(UserMfaRepositoryInterface::class);
        $this->configManager = $this->createMock(ConfigurationManager::class);
        $this->mailService = $this->createMock(MailService::class);

        $this->service = new MfaService(
            $this->userMfaRepository,
            $this->configManager,
            $this->mailService
        );
    }

    protected function tearDown(): void
    {
        // Restore original error_log setting
        ini_set('error_log', $this->originalErrorLog);
        parent::tearDown();
    }

    #[Test]
    public function testGetUserMfaRepositoryReturnsRepository(): void
    {
        $result = $this->service->getUserMfaRepository();
        $this->assertSame($this->userMfaRepository, $result);
    }

    #[Test]
    public function testSaveUserMfaDelegatesToRepository(): void
    {
        $userMfa = $this->createMock(UserMfa::class);

        $this->userMfaRepository->expects($this->once())
            ->method('save')
            ->with($userMfa)
            ->willReturn($userMfa);

        $result = $this->service->saveUserMfa($userMfa);
        $this->assertSame($userMfa, $result);
    }

    #[Test]
    public function testGetUserMfaReturnsUserMfaWhenFound(): void
    {
        $userId = 1;
        $userMfa = $this->createMock(UserMfa::class);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $result = $this->service->getUserMfa($userId);
        $this->assertSame($userMfa, $result);
    }

    #[Test]
    public function testGetUserMfaReturnsNullWhenNotFound(): void
    {
        $userId = 1;

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        $result = $this->service->getUserMfa($userId);
        $this->assertNull($result);
    }

    #[Test]
    public function testGetUserMfaReturnsNullOnDatabaseError(): void
    {
        $userId = 1;

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willThrowException(new \PDOException('Database error'));

        $result = $this->service->getUserMfa($userId);
        $this->assertNull($result);
    }

    #[Test]
    public function testGenerateSecretKeyReturnsBase32EncodedString(): void
    {
        $secret = $this->service->generateSecretKey();

        $this->assertNotEmpty($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    #[Test]
    public function testGenerateSecretKeyGeneratesUniqueValues(): void
    {
        $secret1 = $this->service->generateSecretKey();
        $secret2 = $this->service->generateSecretKey();

        $this->assertNotEquals($secret1, $secret2);
    }

    #[Test]
    public function testIsValidTotpSecretReturnsTrueForValidSecret(): void
    {
        $this->assertTrue($this->service->isValidTotpSecret('JBSWY3DPEHPK3PXP'));
        $this->assertTrue($this->service->isValidTotpSecret('ABCDEFGHIJKLMNOP'));
        $this->assertTrue($this->service->isValidTotpSecret('234567'));
    }

    #[Test]
    public function testIsValidTotpSecretReturnsFalseForInvalidSecret(): void
    {
        $this->assertFalse($this->service->isValidTotpSecret(null));
        $this->assertFalse($this->service->isValidTotpSecret(''));
        $this->assertFalse($this->service->isValidTotpSecret('invalid!@#$'));
        $this->assertFalse($this->service->isValidTotpSecret('abc')); // lowercase not allowed
        $this->assertFalse($this->service->isValidTotpSecret('01890')); // 0, 1, 8, 9 not in Base32
    }

    #[Test]
    public function testGenerateEmailVerificationCodeReturnsSixDigitCode(): void
    {
        $code = $this->service->generateEmailVerificationCode();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertGreaterThanOrEqual(100000, (int)$code);
        $this->assertLessThanOrEqual(999999, (int)$code);
    }

    #[Test]
    public function testGenerateEmailVerificationCodeGeneratesUniqueValues(): void
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = $this->service->generateEmailVerificationCode();
        }

        // At least some codes should be different (statistically very likely)
        $uniqueCodes = array_unique($codes);
        $this->assertGreaterThan(1, count($uniqueCodes));
    }

    #[Test]
    public function testIsMfaEnabledReturnsTrueWhenEnabled(): void
    {
        $userId = 1;
        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('isEnabled')->willReturn(true);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $result = $this->service->isMfaEnabled($userId);
        $this->assertTrue($result);
    }

    #[Test]
    public function testIsMfaEnabledReturnsFalseWhenDisabled(): void
    {
        $userId = 1;
        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('isEnabled')->willReturn(false);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $result = $this->service->isMfaEnabled($userId);
        $this->assertFalse($result);
    }

    #[Test]
    public function testIsMfaEnabledReturnsFalseWhenUserNotFound(): void
    {
        $userId = 1;

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        $result = $this->service->isMfaEnabled($userId);
        $this->assertFalse($result);
    }

    #[Test]
    public function testIsMfaEnabledReturnsFalseOnDatabaseError(): void
    {
        $userId = 1;

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willThrowException(new \PDOException('Database error'));

        $result = $this->service->isMfaEnabled($userId);
        $this->assertFalse($result);
    }

    #[Test]
    public function testGetMfaTypeReturnsTypeWhenFound(): void
    {
        $userId = 1;
        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('getType')->willReturn(UserMfa::TYPE_APP);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $result = $this->service->getMfaType($userId);
        $this->assertEquals(UserMfa::TYPE_APP, $result);
    }

    #[Test]
    public function testGetMfaTypeReturnsNullWhenNotFound(): void
    {
        $userId = 1;

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        $result = $this->service->getMfaType($userId);
        $this->assertNull($result);
    }

    #[Test]
    public function testGetMfaTypeReturnsNullOnDatabaseError(): void
    {
        $userId = 1;

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willThrowException(new \PDOException('Database error'));

        $result = $this->service->getMfaType($userId);
        $this->assertNull($result);
    }

    #[Test]
    public function testGetRecoveryCodesReturnsCodesWhenFound(): void
    {
        $userId = 1;
        $codes = ['code1', 'code2', 'code3'];
        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('getRecoveryCodesAsArray')->willReturn($codes);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $result = $this->service->getRecoveryCodes($userId);
        $this->assertEquals($codes, $result);
    }

    #[Test]
    public function testGetRecoveryCodesReturnsEmptyArrayWhenNotFound(): void
    {
        $userId = 1;

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        $result = $this->service->getRecoveryCodes($userId);
        $this->assertEquals([], $result);
    }

    #[Test]
    public function testDisableMfaReturnsNullWhenUserNotFound(): void
    {
        $userId = 1;

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        $result = $this->service->disableMfa($userId);
        $this->assertNull($result);
    }

    #[Test]
    public function testDisableMfaDisablesAndSaves(): void
    {
        $userId = 1;
        $userMfa = $this->createMock(UserMfa::class);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $userMfa->expects($this->once())->method('disable');

        $this->userMfaRepository->expects($this->once())
            ->method('save')
            ->with($userMfa)
            ->willReturn($userMfa);

        $result = $this->service->disableMfa($userId);
        $this->assertSame($userMfa, $result);
    }

    #[Test]
    public function testVerifyCodeReturnsFalseWhenUserNotFound(): void
    {
        $userId = 1;

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn(null);

        $result = $this->service->verifyCode($userId, '123456');
        $this->assertFalse($result);
    }

    #[Test]
    public function testVerifyCodeReturnsFalseWhenNoSecret(): void
    {
        $userId = 1;
        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('getSecret')->willReturn(null);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $result = $this->service->verifyCode($userId, '123456');
        $this->assertFalse($result);
    }

    #[Test]
    public function testVerifyCodeReturnsTrueForValidRecoveryCode(): void
    {
        $userId = 1;
        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('getSecret')->willReturn('JBSWY3DPEHPK3PXP');
        $userMfa->method('getType')->willReturn(UserMfa::TYPE_APP);
        $userMfa->method('validateRecoveryCode')
            ->with('recovery123')
            ->willReturn(true);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $this->userMfaRepository->expects($this->once())
            ->method('save')
            ->with($userMfa)
            ->willReturn($userMfa);

        $result = $this->service->verifyCode($userId, 'recovery123');
        $this->assertTrue($result);
    }

    #[Test]
    public function testVerifyCodeReturnsTrueForValidEmailCode(): void
    {
        $userId = 1;
        $verificationCode = '123456';
        $metadata = json_encode([
            'expires_at' => time() + 600, // 10 minutes in the future
            'used' => false
        ]);

        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('getSecret')->willReturn($verificationCode);
        $userMfa->method('getType')->willReturn(UserMfa::TYPE_EMAIL);
        $userMfa->method('validateRecoveryCode')->willReturn(false);
        $userMfa->method('getVerificationData')->willReturn($metadata);

        $this->configManager->method('get')
            ->willReturnMap([
                ['mail', 'enabled', false, true],
            ]);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $this->userMfaRepository->expects($this->once())
            ->method('save')
            ->with($userMfa)
            ->willReturn($userMfa);

        $result = $this->service->verifyCode($userId, $verificationCode);
        $this->assertTrue($result);
    }

    #[Test]
    public function testVerifyCodeReturnsFalseForExpiredEmailCode(): void
    {
        $userId = 1;
        $verificationCode = '123456';
        $metadata = json_encode([
            'expires_at' => time() - 600, // 10 minutes in the past (expired)
            'used' => false
        ]);

        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('getSecret')->willReturn($verificationCode);
        $userMfa->method('getType')->willReturn(UserMfa::TYPE_EMAIL);
        $userMfa->method('validateRecoveryCode')->willReturn(false);
        $userMfa->method('getVerificationData')->willReturn($metadata);

        $this->configManager->method('get')
            ->willReturnMap([
                ['mail', 'enabled', false, true],
            ]);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $result = $this->service->verifyCode($userId, $verificationCode);
        $this->assertFalse($result);
    }

    #[Test]
    public function testVerifyCodeReturnsFalseForUsedEmailCode(): void
    {
        $userId = 1;
        $verificationCode = '123456';
        $metadata = json_encode([
            'expires_at' => time() + 600,
            'used' => true // Already used
        ]);

        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('getSecret')->willReturn($verificationCode);
        $userMfa->method('getType')->willReturn(UserMfa::TYPE_EMAIL);
        $userMfa->method('validateRecoveryCode')->willReturn(false);
        $userMfa->method('getVerificationData')->willReturn($metadata);

        $this->configManager->method('get')
            ->willReturnMap([
                ['mail', 'enabled', false, true],
            ]);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $result = $this->service->verifyCode($userId, $verificationCode);
        $this->assertFalse($result);
    }

    #[Test]
    public function testSendEmailVerificationCodeThrowsWhenMailDisabled(): void
    {
        $this->configManager->method('get')
            ->with('mail', 'enabled', false)
            ->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Email verification is not available because mail service is disabled.');

        $this->service->sendEmailVerificationCode(1, 'test@example.com');
    }

    #[Test]
    public function testSendEmailVerificationCodeThrowsWhenEmailEmpty(): void
    {
        $this->configManager->method('get')
            ->with('mail', 'enabled', false)
            ->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Email address is required for email verification.');

        $this->service->sendEmailVerificationCode(1, '');
    }

    #[Test]
    public function testRefreshEmailVerificationCodeIfNeededThrowsWhenMailDisabled(): void
    {
        $this->configManager->method('get')
            ->with('mail', 'enabled', false)
            ->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Email verification is not available because mail service is disabled.');

        $this->service->refreshEmailVerificationCodeIfNeeded(1, 'test@example.com');
    }

    #[Test]
    public function testRefreshEmailVerificationCodeIfNeededReturnsNullForAppType(): void
    {
        $userId = 1;
        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('getType')->willReturn(UserMfa::TYPE_APP);

        $this->configManager->method('get')
            ->with('mail', 'enabled', false)
            ->willReturn(true);

        // Mock isMailConfigurationValid to return true to bypass the validation check
        $this->mailService->method('isMailConfigurationValid')
            ->willReturn(true);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $result = $this->service->refreshEmailVerificationCodeIfNeeded($userId, 'test@example.com');
        $this->assertNull($result);
    }

    #[Test]
    public function testGenerateQrCodeSvgReturnsSvgString(): void
    {
        $this->configManager->method('get')
            ->with('interface', 'title', 'Poweradmin')
            ->willReturn('TestApp');

        $email = 'test@example.com';
        $secret = $this->service->generateSecretKey();

        $result = $this->service->generateQrCodeSvg($email, $secret);

        $this->assertStringContainsString('<svg', $result);
        $this->assertStringContainsString('</svg>', $result);
    }

    #[Test]
    public function testVerifyCodeReturnsFalseForInvalidTotpSecretFormat(): void
    {
        $userId = 1;
        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('getSecret')->willReturn('invalid!secret'); // Invalid Base32
        $userMfa->method('getType')->willReturn(UserMfa::TYPE_APP);
        $userMfa->method('validateRecoveryCode')->willReturn(false);

        $this->userMfaRepository->method('findByUserId')
            ->with($userId)
            ->willReturn($userMfa);

        $result = $this->service->verifyCode($userId, '123456');
        $this->assertFalse($result);
    }
}
