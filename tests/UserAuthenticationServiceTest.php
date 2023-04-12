<?php

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Services\UserAuthenticationService;

class UserAuthenticationServiceTest extends TestCase
{
    protected UserAuthenticationService $password;

    protected function setUp(): void
    {
        global $password_encryption_cost;
        $password_encryption_cost = 10;

        $this->password = new UserAuthenticationService();
    }

    public function testSaltLength(): void
    {
        $len = 5;
        $salt = $this->password->generateSalt($len);
        $this->assertEquals($len, strlen($salt), 'Generated salt length should match the input length');
    }

    public function testSaltCharacters(): void
    {
        $len = 10;
        $salt = $this->password->generateSalt($len);
        $valid_characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890@#$%^*()_-!';
        $valid_characters_array = str_split($valid_characters);

        for ($i = 0; $i < $len; $i++) {
            $this->assertContains($salt[$i], $valid_characters_array, 'Generated salt should only contain valid characters');
        }
    }

    public function testSaltEdgeCaseZeroLength(): void
    {
        $len = 0;
        $salt = $this->password->generateSalt($len);
        $this->assertEquals($len, strlen($salt), 'Generated salt length should be 0 when input length is 0');
    }

    public function testSaltEdgeCaseNegativeLength(): void
    {
        $len = -5;
        $salt = $this->password->generateSalt($len);
        $this->assertEmpty($salt, 'Generated salt should be empty when input length is negative');
    }

    public function testSaltUniqueness(): void
    {
        $len = 10;
        $salt1 = $this->password->generateSalt($len);
        $salt2 = $this->password->generateSalt($len);

        $this->assertNotEquals($salt1, $salt2, 'Two generated salts should not be equal');
    }

    public function testHashMd5(): void
    {
        global $password_encryption;
        $password_encryption = 'md5';
        $password = 'test_password';

        $hash = $this->password->hashPassword($password);
        $this->assertEquals(md5($password), $hash, 'Generated hash should match MD5 hash');
    }

    public function testHashMd5Salt(): void
    {
        global $password_encryption;
        $password_encryption = 'md5salt';
        $password = 'test_password';

        $hash = $this->password->hashPassword($password);
        $salt = $this->password->extractUserSalt($hash);
        $expected_hash = $this->password->combineSalts($salt, $password);

        $this->assertEquals($expected_hash, $hash, 'Generated hash should match MD5 hash with salt');
    }

    public function testHashBcrypt(): void
    {
        global $password_encryption;
        $password_encryption = 'bcrypt';
        $password = 'test_password';

        $hash = $this->password->hashPassword($password);
        $this->assertTrue(password_verify($password, $hash), 'Generated hash should match bcrypt hash');
    }

    public function testHashEdgeCaseEmptyPassword(): void
    {
        global $password_encryption;
        $password_encryption = 'md5';
        $password = '';

        $hash = $this->password->hashPassword($password);
        $this->assertEquals(md5($password), $hash, 'Generated hash should match MD5 hash for an empty password');
    }

    public function testVerifyMd5(): void
    {
        global $password_encryption;
        $password_encryption = 'md5';
        $password = 'test_password';

        $hash = $this->password->hashPassword($password);
        $result = $this->password->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for MD5 hash');
    }

    public function testVerifyMd5Salt(): void
    {
        global $password_encryption;
        $password_encryption = 'md5salt';
        $password = 'test_password';

        $hash = $this->password->hashPassword($password);
        $result = $this->password->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for MD5 hash with salt');
    }

    public function testVerifyBcrypt(): void
    {
        global $password_encryption;
        $password_encryption = 'bcrypt';
        $password = 'test_password';

        $hash = $this->password->hashPassword($password);
        $result = $this->password->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for bcrypt hash');
    }

    public function testVerifyEdgeCaseIncorrectPassword(): void
    {
        global $password_encryption;
        $password_encryption = 'md5';
        $password = 'test_password';
        $incorrect_password = 'wrong_password';

        $hash = $this->password->hashPassword($password);
        $result = $this->password->verifyPassword($incorrect_password, $hash);

        $this->assertFalse($result, 'Password verification should fail for an incorrect password');
    }

    public function testVerifyEdgeCaseEmptyPassword(): void
    {
        global $password_encryption;
        $password_encryption = 'md5';
        $password = 'test_password';
        $empty_password = '';

        $hash = $this->password->hashPassword($password);
        $result = $this->password->verifyPassword($empty_password, $hash);

        $this->assertFalse($result, 'Password verification should fail for an empty password');
    }

    public function testNeedsRehashBcrypt(): void
    {
        global $password_encryption, $password_encryption_cost;
        $password_encryption = 'bcrypt';
        $password_encryption_cost = 10;
        $password = 'test_password';

        $hash = $this->password->hashPassword($password);
        $result = $this->password->requiresRehash($hash);

        $this->assertFalse($result, 'Password hash should not need rehash for correct bcrypt cost');
    }

    public function testNeedsRehashBcryptChangedCost(): void
    {
        global $password_encryption, $password_encryption_cost;
        $password_encryption = 'bcrypt';
        $password_encryption_cost = 10;
        $password = 'test_password';

        $hash = $this->password->hashPassword($password);
        $password_encryption_cost = 12;
        $result = $this->password->requiresRehash($hash);

        $this->assertTrue($result, 'Password hash should need rehash for changed bcrypt cost');
    }

    public function testNeedsRehashChangedEncryption(): void
    {
        global $password_encryption;
        $password_encryption = 'md5';
        $password = 'test_password';

        $hash = $this->password->hashPassword($password);
        $password_encryption = 'bcrypt';
        $result = $this->password->requiresRehash($hash);

        $this->assertTrue($result, 'Password hash should need rehash for changed encryption method');
    }

    public function testNeedsRehashEdgeCaseInvalidHash(): void
    {
        $invalid_hash = 'invalid_hash';

        $this->expectException(InvalidArgumentException::class);
        $this->password->requiresRehash($invalid_hash);
    }

    public function testDetermineHashAlgorithmMd5(): void
    {
        $md5_hash = md5('test_password');
        $hash_type = $this->password->identifyHashAlgorithm($md5_hash);

        $this->assertEquals('md5', $hash_type, 'Hash type should be determined as MD5');
    }

    public function testDetermineHashAlgorithmMd5Salt(): void
    {
        global $password_encryption;
        $password_encryption = 'md5salt';
        $password = 'test_password';

        $md5salt_hash = $this->password->hashPassword($password);
        $hash_type = $this->password->identifyHashAlgorithm($md5salt_hash);

        $this->assertEquals('md5salt', $hash_type, 'Hash type should be determined as MD5 with salt');
    }

    public function testDetermineHashAlgorithmBcrypt(): void
    {
        global $password_encryption;
        $password_encryption = 'bcrypt';
        $password = 'test_password';

        $bcrypt_hash = $this->password->hashPassword($password);
        $hash_type = $this->password->identifyHashAlgorithm($bcrypt_hash);

        $this->assertEquals('bcrypt', $hash_type, 'Hash type should be determined as bcrypt');
    }

    public function testDetermineHashAlgorithmEdgeCaseInvalidHash(): void
    {
        $invalid_hash = 'invalid_hash';

        $this->expectException(InvalidArgumentException::class);
        $this->password->identifyHashAlgorithm($invalid_hash);
    }

    public function testGenMixSalt(): void
    {
        $password = 'test_password';

        $md5salt_hash = $this->password->generateCombinedSalt($password);
        $hash_parts = explode(':', $md5salt_hash);

        $this->assertCount(2, $hash_parts, 'MD5 with salt hash should contain two parts separated by a colon');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash_parts[0], 'First part of the hash should be a 32-character MD5 hash');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9@#$%^*()_\-!]{5}$/', $hash_parts[1], 'Second part of the hash should be a 5-character salt');
    }

    public function testGenMixSaltEdgeCaseEmptyPassword(): void
    {
        $password = '';

        $md5salt_hash = $this->password->generateCombinedSalt($password);
        $hash_parts = explode(':', $md5salt_hash);

        $this->assertCount(2, $hash_parts, 'MD5 with salt hash should contain two parts separated by a colon');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash_parts[0], 'First part of the hash should be a 32-character MD5 hash');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9@#$%^*()_\-!]{5}$/', $hash_parts[1], 'Second part of the hash should be a 5-character salt');
    }

    public function testMixSalt(): void
    {
        $password = 'test_password';
        $salt = 'abcde';

        $md5salt_hash = $this->password->combineSalts($salt, $password);
        $hash_parts = explode(':', $md5salt_hash);

        $this->assertCount(2, $hash_parts, 'MD5 with salt hash should contain two parts separated by a colon');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash_parts[0], 'First part of the hash should be a 32-character MD5 hash');
        $this->assertEquals($salt, $hash_parts[1], 'Second part of the hash should be the provided salt');
    }

    public function testMixSaltEdgeCaseEmptyPassword(): void
    {
        $password = '';
        $salt = 'abcde';

        $md5salt_hash = $this->password->combineSalts($salt, $password);
        $hash_parts = explode(':', $md5salt_hash);

        $this->assertCount(2, $hash_parts, 'MD5 with salt hash should contain two parts separated by a colon');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash_parts[0], 'First part of the hash should be a 32-character MD5 hash');
        $this->assertEquals($salt, $hash_parts[1], 'Second part of the hash should be the provided salt');
    }

    public function testMixSaltEdgeCaseEmptySalt(): void
    {
        $password = 'test_password';
        $salt = '';

        $md5salt_hash = $this->password->combineSalts($salt, $password);
        $hash_parts = explode(':', $md5salt_hash);

        $this->assertCount(2, $hash_parts, 'MD5 with salt hash should contain two parts separated by a colon');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash_parts[0], 'First part of the hash should be a 32-character MD5 hash');
        $this->assertEquals($salt, $hash_parts[1], 'Second part of the hash should be the provided salt');
    }

    public function testExtractSalt(): void
    {
        $md5salt_hash = '0cc175b9c0f1b6a831c399e269772661:abcde';

        $extracted_salt = $this->password->extractUserSalt($md5salt_hash);

        $this->assertEquals('abcde', $extracted_salt, 'Salt should be extracted correctly from the MD5 with salt hash');
    }

    public function testExtractSaltEdgeCaseNoSalt(): void
    {
        $md5_hash = '0cc175b9c0f1b6a831c399e269772661';

        $extracted_salt = $this->password->extractUserSalt($md5_hash);

        $this->assertEquals('', $extracted_salt, 'Empty salt should be returned if the hash does not contain a salt');
    }

    public function testExtractSaltEdgeCaseInvalidHash(): void
    {
        $invalid_hash = 'invalid_hash';

        $extracted_salt = $this->password->extractUserSalt($invalid_hash);

        $this->assertEquals('', $extracted_salt, 'Empty salt should be returned if the hash is invalid');
    }

    public function testHashAndVerifySpecialChars(): void
    {
        global $password_encryption;
        $password_encryption = 'bcrypt';
        $password = 'test_p@$$w0rd!';

        $hash = $this->password->hashPassword($password);
        $result = $this->password->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for a password containing special characters');
    }

    public function testHashAndVerifyLongPassword(): void
    {
        global $password_encryption;
        $password_encryption = 'bcrypt';
        $password = str_repeat('a', 4096);

        $hash = $this->password->hashPassword($password);
        $result = $this->password->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for a very long password');
    }

    public function testHashAndVerifyUnicodePassword(): void
    {
        global $password_encryption;
        $password_encryption = 'bcrypt';
        $password = 'tést_pàsswórd';

        $hash = $this->password->hashPassword($password);
        $result = $this->password->verifyPassword($password, $hash);

        $this->assertTrue($result, 'Password verification should be successful for a unicode password');
    }
}
