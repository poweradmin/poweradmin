<?php

namespace unit;

use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ApiKey;

#[CoversClass(ApiKey::class)]
class ApiKeyModelTest extends TestCase
{
    #[Test]
    public function constructorAndGetters(): void
    {
        $createdAt = new DateTime('2025-01-01');
        $expiresAt = new DateTime('2026-01-01');
        $lastUsed = new DateTime('2025-06-01');

        $key = new ApiKey('Test Key', 'pwa_abc123', 1, $createdAt, $lastUsed, false, $expiresAt, 42);

        $this->assertSame(42, $key->getId());
        $this->assertSame('Test Key', $key->getName());
        $this->assertSame('pwa_abc123', $key->getSecretKey());
        $this->assertSame(1, $key->getCreatedBy());
        $this->assertSame($createdAt, $key->getCreatedAt());
        $this->assertSame($lastUsed, $key->getLastUsedAt());
        $this->assertFalse($key->isDisabled());
        $this->assertSame($expiresAt, $key->getExpiresAt());
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $key = new ApiKey('Test', 'pwa_secret');

        $this->assertNull($key->getId());
        $this->assertNull($key->getCreatedBy());
        $this->assertNull($key->getLastUsedAt());
        $this->assertFalse($key->isDisabled());
        $this->assertNull($key->getExpiresAt());
        $this->assertInstanceOf(DateTime::class, $key->getCreatedAt());
    }

    #[Test]
    public function generateSecretKeyStartsWithPrefix(): void
    {
        $key = ApiKey::generateSecretKey();

        $this->assertStringStartsWith('pwa_', $key);
        $this->assertSame(68, strlen($key)); // pwa_ (4) + 32 bytes hex (64)
    }

    #[Test]
    public function generateSecretKeyIsUnique(): void
    {
        $key1 = ApiKey::generateSecretKey();
        $key2 = ApiKey::generateSecretKey();

        $this->assertNotSame($key1, $key2);
    }

    #[Test]
    public function hasExpiredWithPastDate(): void
    {
        $key = new ApiKey('Test', 'pwa_abc', null, null, null, false, new DateTime('2024-01-01'));

        $this->assertTrue($key->hasExpired());
    }

    #[Test]
    public function hasExpiredWithFutureDate(): void
    {
        $key = new ApiKey('Test', 'pwa_abc', null, null, null, false, new DateTime('2099-01-01'));

        $this->assertFalse($key->hasExpired());
    }

    #[Test]
    public function hasExpiredWithNullDate(): void
    {
        $key = new ApiKey('Test', 'pwa_abc', null, null, null, false, null);

        $this->assertFalse($key->hasExpired());
    }

    #[Test]
    public function isValidCombinesDisabledAndExpired(): void
    {
        // Not disabled, not expired
        $valid = new ApiKey('Test', 'pwa_abc', null, null, null, false, new DateTime('2099-01-01'));
        $this->assertTrue($valid->isValid());

        // Disabled, not expired
        $disabled = new ApiKey('Test', 'pwa_abc', null, null, null, true, new DateTime('2099-01-01'));
        $this->assertFalse($disabled->isValid());

        // Not disabled, expired
        $expired = new ApiKey('Test', 'pwa_abc', null, null, null, false, new DateTime('2024-01-01'));
        $this->assertFalse($expired->isValid());

        // Disabled and expired
        $both = new ApiKey('Test', 'pwa_abc', null, null, null, true, new DateTime('2024-01-01'));
        $this->assertFalse($both->isValid());

        // No expiry, not disabled
        $noExpiry = new ApiKey('Test', 'pwa_abc');
        $this->assertTrue($noExpiry->isValid());
    }

    #[Test]
    public function jsonSerializeExcludesSecretKey(): void
    {
        $key = new ApiKey('Test', 'pwa_secret123', 1, new DateTime('2025-01-01'), null, false, null, 5);
        $json = $key->jsonSerialize();

        $this->assertArrayHasKey('id', $json);
        $this->assertArrayHasKey('name', $json);
        $this->assertArrayNotHasKey('secretKey', $json);
        $this->assertSame(5, $json['id']);
        $this->assertSame('Test', $json['name']);
        $this->assertSame(1, $json['createdBy']);
        $this->assertFalse($json['disabled']);
        $this->assertNull($json['lastUsedAt']);
        $this->assertNull($json['expiresAt']);
        $this->assertFalse($json['isExpired']);
        $this->assertTrue($json['isValid']);
    }

    #[Test]
    public function regenerateSecretKey(): void
    {
        $key = new ApiKey('Test', 'pwa_old_key');
        $oldKey = $key->getSecretKey();

        $newKey = $key->regenerateSecretKey();

        $this->assertNotSame($oldKey, $newKey);
        $this->assertStringStartsWith('pwa_', $newKey);
        $this->assertSame($newKey, $key->getSecretKey());
    }

    #[Test]
    public function updateLastUsed(): void
    {
        $key = new ApiKey('Test', 'pwa_abc');
        $this->assertNull($key->getLastUsedAt());

        $key->updateLastUsed();

        $this->assertInstanceOf(DateTime::class, $key->getLastUsedAt());
    }

    #[Test]
    public function settersWorkCorrectly(): void
    {
        $key = new ApiKey('Original', 'pwa_original');

        $key->setId(10);
        $key->setName('Updated');
        $key->setSecretKey('pwa_updated');
        $key->setCreatedBy(5);
        $key->setDisabled(true);
        $key->setCreatorUsername('admin');
        $key->setCreatorFullname('Admin User');

        $this->assertSame(10, $key->getId());
        $this->assertSame('Updated', $key->getName());
        $this->assertSame('pwa_updated', $key->getSecretKey());
        $this->assertSame(5, $key->getCreatedBy());
        $this->assertTrue($key->isDisabled());
        $this->assertSame('admin', $key->getCreatorUsername());
        $this->assertSame('Admin User', $key->getCreatorFullname());
    }
}
