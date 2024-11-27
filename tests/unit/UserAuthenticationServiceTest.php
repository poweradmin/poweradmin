<?php

namespace unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\UserAuthenticationService;

class UserAuthenticationServiceTest extends TestCase
{
    protected UserAuthenticationService $userAuthService;

    protected function setUp(): void
    {
        $passwordEncryption = 'bcrypt';
        $passwordEncryptionCost = 12;

        $this->userAuthService = new UserAuthenticationService($passwordEncryption, $passwordEncryptionCost);
    }

    public function testSaltLength(): void
    {
        $len = 5;
        $salt = $this->userAuthService->generateSalt($len);
        $this->assertEquals($len, strlen($salt), 'Generated salt length should match the input length');
    }

    public function testSaltCharacters(): void
    {
        $len = 10;
        $salt = $this->userAuthService->generateSalt($len);
        $valid_characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890@#$%^*()_-!';
        $valid_characters_array = str_split($valid_characters);

        for ($i = 0; $i < $len; $i++) {
            $this->assertContains($salt[$i], $valid_characters_array, 'Generated salt should only contain valid characters');
        }
    }

    public function testSaltEdgeCaseZeroLength(): void
    {
        $len = 0;
        $salt = $this->userAuthService->generateSalt($len);
        $this->assertEquals($len, strlen($salt), 'Generated salt length should be 0 when input length is 0');
    }

    public function testSaltEdgeCaseNegativeLength(): void
    {
        $len = -5;
        $salt = $this->userAuthService->generateSalt($len);
        $this->assertEmpty($salt, 'Generated salt should be empty when input length is negative');
    }

    public function testSaltUniqueness(): void
    {
        $len = 10;
        $salt1 = $this->userAuthService->generateSalt($len);
        $salt2 = $this->userAuthService->generateSalt($len);

        $this->assertNotEquals($salt1, $salt2, 'Two generated salts should not be equal');
    }

    public function testHashMd5(): void
    {
        $password_encryption = 'md5';
        $password = 'test_password';

        $userAuthService = new UserAuthenticationService($password_encryption);
        $hash = $userAuthService->hashPassword($password);
        $this->assertEquals(md5($password), $hash, 'Generated hash should match MD5 hash');
    }

    public function testHashMd5Salt(): void
    {
        $userAuthService = new UserAuthenticationService('md5salt');
        $password = 'test_password';

        $hash = $userAuthService->hashPassword($password);
        $salt = $userAuthService->extractUserSalt($hash);
        $expected_hash = $userAuthService->combineSalts($salt, $password);

        $this->assertEquals($expected_hash, $hash, 'Generated hash should match MD5 hash with salt');
    }

    public function testHashBcrypt(): void
    {
        $password = 'test_password';

        $hash = $this->userAuthService->hashPassword($password);
        $this->assertTrue(password_verify($password, $hash), 'Generated hash should match bcrypt hash');
    }

    public function testHashEdgeCaseEmptyPassword(): void
    {
        $userAuthService = new UserAuthenticationService('md5');
        $password = '';

        $hash = $userAuthService->hashPassword($password);
        $this->assertEquals(md5($password), $hash, 'Generated hash should match MD5 hash for an empty password');
    }

    public function testVerifyMd5(): void
    {
        $password_encryption = 'md5';
        $password = 'test_password';

        $userAuthService = new UserAuthenticationService($password_encryption);
        $hash = $userAuthService->hashPassword($password);
        $result = $userAuthService->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for MD5 hash');
    }

    public function testVerifyMd5Salt(): void
    {
        $password_encryption = 'md5salt';
        $password = 'test_password';

        $userAuthService = new UserAuthenticationService($password_encryption);
        $hash = $userAuthService->hashPassword($password);
        $result = $userAuthService->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for MD5 hash with salt');
    }

    public function testVerifyBcrypt(): void
    {
        $password = 'test_password';

        $hash = $this->userAuthService->hashPassword($password);
        $result = $this->userAuthService->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for bcrypt hash');
    }

    public function testVerifyEdgeCaseIncorrectPassword(): void
    {
        $password_encryption = 'md5';
        $password = 'test_password';
        $incorrect_password = 'wrong_password';

        $userAuthService = new UserAuthenticationService($password_encryption);
        $hash = $userAuthService->hashPassword($password);
        $result = $userAuthService->verifyPassword($incorrect_password, $hash);

        $this->assertFalse($result, 'Password verification should fail for an incorrect password');
    }

    public function testVerifyEdgeCaseEmptyPassword(): void
    {
        $password_encryption = 'md5';
        $password = 'test_password';
        $empty_password = '';

        $userAuthService = new UserAuthenticationService($password_encryption);
        $hash = $userAuthService->hashPassword($password);
        $result = $userAuthService->verifyPassword($empty_password, $hash);

        $this->assertFalse($result, 'Password verification should fail for an empty password');
    }

    public function testNeedsRehashBcrypt(): void
    {
        $password_encryption = 'bcrypt';
        $password_encryption_cost = 10;
        $password = 'test_password';

        $userAuthService = new UserAuthenticationService($password_encryption, $password_encryption_cost);
        $hash = $userAuthService->hashPassword($password);
        $result = $userAuthService->requiresRehash($hash);

        $this->assertFalse($result, 'Password hash should not need rehash for correct bcrypt cost');
    }

    public function testNeedsRehashBcryptChangedCost(): void
    {
        $userAuthServiceOld = new UserAuthenticationService('bcrypt', 10);
        $password = 'test_password';

        $hash = $userAuthServiceOld->hashPassword($password);

        $userAuthServiceNew = new UserAuthenticationService('bcrypt', 11);
        $result = $userAuthServiceNew->requiresRehash($hash);

        $this->assertTrue($result, 'Password hash should need rehash for changed bcrypt cost');
    }

    public function testNeedsRehashChangedEncryption(): void
    {
        $userAuthServiceOld = new UserAuthenticationService('md5');
        $password = 'test_password';

        $hash = $userAuthServiceOld->hashPassword($password);

        $userAuthServiceNew = new UserAuthenticationService('bcrypt');
        $result = $userAuthServiceNew->requiresRehash($hash);

        $this->assertTrue($result, 'Password hash should need rehash for changed encryption method');
    }

    public function testNeedsRehashEdgeCaseInvalidHash(): void
    {
        $invalid_hash = 'invalid_hash';

        $this->expectException(InvalidArgumentException::class);
        $this->userAuthService->requiresRehash($invalid_hash);
    }

    public function testDetermineHashAlgorithmMd5(): void
    {
        $md5_hash = md5('test_password');
        $hash_type = $this->userAuthService->identifyHashAlgorithm($md5_hash);

        $this->assertEquals('md5', $hash_type, 'Hash type should be determined as MD5');
    }

    public function testDetermineHashAlgorithmMd5Salt(): void
    {
        $userAuthService = new UserAuthenticationService('md5salt');
        $password = 'test_password';

        $md5salt_hash = $userAuthService->hashPassword($password);
        $hash_type = $userAuthService->identifyHashAlgorithm($md5salt_hash);

        $this->assertEquals('md5salt', $hash_type, 'Hash type should be determined as MD5 with salt');
    }

    public function testDetermineHashAlgorithmBcrypt(): void
    {
        $password = 'test_password';

        $bcrypt_hash = $this->userAuthService->hashPassword($password);
        $hash_type = $this->userAuthService->identifyHashAlgorithm($bcrypt_hash);

        $this->assertEquals('bcrypt', $hash_type, 'Hash type should be determined as bcrypt');
    }

    public function testDetermineHashAlgorithmEdgeCaseInvalidHash(): void
    {
        $invalid_hash = 'invalid_hash';

        $this->expectException(InvalidArgumentException::class);
        $this->userAuthService->identifyHashAlgorithm($invalid_hash);
    }

    public function testGenMixSalt(): void
    {
        $password = 'test_password';

        $md5salt_hash = $this->userAuthService->generateCombinedSalt($password);
        $hash_parts = explode(':', $md5salt_hash);

        $this->assertCount(2, $hash_parts, 'MD5 with salt hash should contain two parts separated by a colon');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash_parts[0], 'First part of the hash should be a 32-character MD5 hash');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9@#$%^*()_\-!]{5}$/', $hash_parts[1], 'Second part of the hash should be a 5-character salt');
    }

    public function testGenMixSaltEdgeCaseEmptyPassword(): void
    {
        $password = '';

        $md5salt_hash = $this->userAuthService->generateCombinedSalt($password);
        $hash_parts = explode(':', $md5salt_hash);

        $this->assertCount(2, $hash_parts, 'MD5 with salt hash should contain two parts separated by a colon');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash_parts[0], 'First part of the hash should be a 32-character MD5 hash');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9@#$%^*()_\-!]{5}$/', $hash_parts[1], 'Second part of the hash should be a 5-character salt');
    }

    public function testMixSalt(): void
    {
        $password = 'test_password';
        $salt = 'abcde';

        $md5salt_hash = $this->userAuthService->combineSalts($salt, $password);
        $hash_parts = explode(':', $md5salt_hash);

        $this->assertCount(2, $hash_parts, 'MD5 with salt hash should contain two parts separated by a colon');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash_parts[0], 'First part of the hash should be a 32-character MD5 hash');
        $this->assertEquals($salt, $hash_parts[1], 'Second part of the hash should be the provided salt');
    }

    public function testMixSaltEdgeCaseEmptyPassword(): void
    {
        $password = '';
        $salt = 'abcde';

        $md5salt_hash = $this->userAuthService->combineSalts($salt, $password);
        $hash_parts = explode(':', $md5salt_hash);

        $this->assertCount(2, $hash_parts, 'MD5 with salt hash should contain two parts separated by a colon');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash_parts[0], 'First part of the hash should be a 32-character MD5 hash');
        $this->assertEquals($salt, $hash_parts[1], 'Second part of the hash should be the provided salt');
    }

    public function testMixSaltEdgeCaseEmptySalt(): void
    {
        $password = 'test_password';
        $salt = '';

        $md5salt_hash = $this->userAuthService->combineSalts($salt, $password);
        $hash_parts = explode(':', $md5salt_hash);

        $this->assertCount(2, $hash_parts, 'MD5 with salt hash should contain two parts separated by a colon');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash_parts[0], 'First part of the hash should be a 32-character MD5 hash');
        $this->assertEquals($salt, $hash_parts[1], 'Second part of the hash should be the provided salt');
    }

    public function testExtractSalt(): void
    {
        $md5salt_hash = '0cc175b9c0f1b6a831c399e269772661:abcde';

        $extracted_salt = $this->userAuthService->extractUserSalt($md5salt_hash);

        $this->assertEquals('abcde', $extracted_salt, 'Salt should be extracted correctly from the MD5 with salt hash');
    }

    public function testExtractSaltEdgeCaseNoSalt(): void
    {
        $md5_hash = '0cc175b9c0f1b6a831c399e269772661';

        $extracted_salt = $this->userAuthService->extractUserSalt($md5_hash);

        $this->assertEquals('', $extracted_salt, 'Empty salt should be returned if the hash does not contain a salt');
    }

    public function testExtractSaltEdgeCaseInvalidHash(): void
    {
        $invalid_hash = 'invalid_hash';

        $extracted_salt = $this->userAuthService->extractUserSalt($invalid_hash);

        $this->assertEquals('', $extracted_salt, 'Empty salt should be returned if the hash is invalid');
    }

    public function testHashAndVerifySpecialChars(): void
    {
        $password = 'test_p@$$w0rd!';

        $hash = $this->userAuthService->hashPassword($password);
        $result = $this->userAuthService->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for a password containing special characters');
    }

    public function testHashAndVerifyLongPassword(): void
    {
        $password = str_repeat('a', 4096);

        $hash = $this->userAuthService->hashPassword($password);
        $result = $this->userAuthService->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for a very long password');
    }

    public function testHashAndVerifyUnicodePassword(): void
    {
        $password = 'tést_pàsswórd';

        $hash = $this->userAuthService->hashPassword($password);
        $result = $this->userAuthService->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for a unicode password');
    }
}
