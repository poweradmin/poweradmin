<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\PdnsTable;

class PdnsTableTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertEquals('domains', PdnsTable::DOMAINS->value);
        $this->assertEquals('records', PdnsTable::RECORDS->value);
        $this->assertEquals('supermasters', PdnsTable::SUPERMASTERS->value);
        $this->assertEquals('comments', PdnsTable::COMMENTS->value);
        $this->assertEquals('domainmetadata', PdnsTable::DOMAINMETADATA->value);
        $this->assertEquals('cryptokeys', PdnsTable::CRYPTOKEYS->value);
        $this->assertEquals('tsigkeys', PdnsTable::TSIGKEYS->value);
    }

    public function testGetFullNameWithoutPrefix(): void
    {
        $this->assertEquals('domains', PdnsTable::DOMAINS->getFullName());
        $this->assertEquals('records', PdnsTable::RECORDS->getFullName());
        $this->assertEquals('comments', PdnsTable::COMMENTS->getFullName());
    }

    public function testGetFullNameWithPrefix(): void
    {
        $this->assertEquals('test.domains', PdnsTable::DOMAINS->getFullName('test'));
        $this->assertEquals('pdns_prod.records', PdnsTable::RECORDS->getFullName('pdns_prod'));
        $this->assertEquals('mydb.comments', PdnsTable::COMMENTS->getFullName('mydb'));
    }

    public function testGetFullNameWithNullPrefix(): void
    {
        $this->assertEquals('domains', PdnsTable::DOMAINS->getFullName(null));
        $this->assertEquals('records', PdnsTable::RECORDS->getFullName(null));
    }

    public function testGetFullNameWithEmptyPrefix(): void
    {
        $this->assertEquals('domains', PdnsTable::DOMAINS->getFullName(''));
        $this->assertEquals('records', PdnsTable::RECORDS->getFullName(''));
    }

    public function testGetAllTableNames(): void
    {
        $expected = [
            'domains',
            'records',
            'supermasters',
            'comments',
            'domainmetadata',
            'cryptokeys',
            'tsigkeys'
        ];

        $actual = PdnsTable::getAllTableNames();

        $this->assertCount(7, $actual);
        $this->assertEquals($expected, $actual);
    }

    public function testIsValidTableName(): void
    {
        // Valid table names
        $this->assertTrue(PdnsTable::isValidTableName('domains'));
        $this->assertTrue(PdnsTable::isValidTableName('records'));
        $this->assertTrue(PdnsTable::isValidTableName('comments'));
        $this->assertTrue(PdnsTable::isValidTableName('cryptokeys'));

        // Invalid table names
        $this->assertFalse(PdnsTable::isValidTableName('invalid_table'));
        $this->assertFalse(PdnsTable::isValidTableName('users'));
        $this->assertFalse(PdnsTable::isValidTableName(''));
        $this->assertFalse(PdnsTable::isValidTableName('domain')); // singular vs plural
    }

    public function testFromStringValid(): void
    {
        $this->assertEquals(PdnsTable::DOMAINS, PdnsTable::fromString('domains'));
        $this->assertEquals(PdnsTable::RECORDS, PdnsTable::fromString('records'));
        $this->assertEquals(PdnsTable::COMMENTS, PdnsTable::fromString('comments'));
        $this->assertEquals(PdnsTable::CRYPTOKEYS, PdnsTable::fromString('cryptokeys'));
        $this->assertEquals(PdnsTable::DOMAINMETADATA, PdnsTable::fromString('domainmetadata'));
        $this->assertEquals(PdnsTable::SUPERMASTERS, PdnsTable::fromString('supermasters'));
        $this->assertEquals(PdnsTable::TSIGKEYS, PdnsTable::fromString('tsigkeys'));
    }

    public function testFromStringInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name not allowed for prefixing: invalid_table');

        PdnsTable::fromString('invalid_table');
    }

    public function testFromStringEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name not allowed for prefixing: ');

        PdnsTable::fromString('');
    }

    public function testFromStringWithTypos(): void
    {
        $invalidNames = [
            'recrods',      // typo in records
            'domain',       // singular instead of plural
            'comment',      // singular instead of plural
            'cryptokey',    // singular instead of plural
            'domainmeta',   // truncated
        ];

        foreach ($invalidNames as $invalidName) {
            try {
                PdnsTable::fromString($invalidName);
                $this->fail("Expected InvalidArgumentException for: $invalidName");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Table name not allowed for prefixing:', $e->getMessage());
            }
        }
    }

    public function testAllEnumCasesHaveValidValues(): void
    {
        $cases = PdnsTable::cases();

        $this->assertCount(7, $cases);

        // Verify each case has a valid string value
        foreach ($cases as $case) {
            $this->assertIsString($case->value);
            $this->assertNotEmpty($case->value);
            $this->assertMatchesRegularExpression('/^[a-z]+$/', $case->value);
        }
    }
}
