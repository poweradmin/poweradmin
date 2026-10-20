<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbZoneMetadataStore;
use TestHelpers\FakeConfiguration;
use Poweradmin\Infrastructure\Service\NullDnssecProvider;

class NullDnssecProviderTest extends TestCase
{
    public function testWithoutThePowerDnsApiKeysCannotBeAskedOrCreated(): void
    {
        $provider = new NullDnssecProvider();

        $this->assertNull($provider->fetchZoneKeys('example.com'));
        $this->assertNull($provider->createZoneKey('example.com', 'csk', 256, 'ecdsa256', false));
    }

    public function testPresignedIsFalseWithoutAnSqlMetadataStore(): void
    {
        $this->assertFalse((new NullDnssecProvider())->isZonePresigned('example.com'));
    }

    public function testPresignedIsAnsweredByTheSqlMetadataStore(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER, kind TEXT, content TEXT)');
        $db->exec("INSERT INTO domains (id, name) VALUES (3, 'signed.example')");
        $db->exec("INSERT INTO domainmetadata (domain_id, kind, content) VALUES (3, 'PRESIGNED', '1')");
        $provider = new NullDnssecProvider(new DbZoneMetadataStore($db, new FakeConfiguration()));

        $this->assertTrue($provider->isZonePresigned('signed.example'));
        $this->assertFalse($provider->isZonePresigned('example.com'));
    }
}
