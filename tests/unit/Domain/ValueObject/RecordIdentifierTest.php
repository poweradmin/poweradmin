<?php

namespace Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\ValueObject\RecordIdentifier;

class RecordIdentifierTest extends TestCase
{
    public function testEncodeAndDecode(): void
    {
        $encoded = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

        $decoded = RecordIdentifier::decode($encoded);

        $this->assertEquals('example.com', $decoded['zone_name']);
        $this->assertEquals('www.example.com', $decoded['name']);
        $this->assertEquals('A', $decoded['type']);
        $this->assertEquals('192.168.1.1', $decoded['content']);
        $this->assertEquals(0, $decoded['prio']);
    }

    public function testEncodeWithPriority(): void
    {
        $encoded = RecordIdentifier::encode('example.com', 'example.com', 'MX', 'mail.example.com', 10);

        $decoded = RecordIdentifier::decode($encoded);

        $this->assertEquals('MX', $decoded['type']);
        $this->assertEquals('mail.example.com', $decoded['content']);
        $this->assertEquals(10, $decoded['prio']);
    }

    public function testIsEncodedWithInteger(): void
    {
        $this->assertFalse(RecordIdentifier::isEncoded(42));
    }

    public function testIsEncodedWithNumericString(): void
    {
        $this->assertFalse(RecordIdentifier::isEncoded('42'));
    }

    public function testIsEncodedWithEncodedString(): void
    {
        $encoded = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

        $this->assertTrue(RecordIdentifier::isEncoded($encoded));
    }

    public function testEncodedStringIsUrlSafe(): void
    {
        $encoded = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-]+$/', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }

    public function testDecodeInvalidThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecordIdentifier::decode('not-valid-base64-json');
    }

    public function testRoundTripWithSpecialCharacters(): void
    {
        $encoded = RecordIdentifier::encode(
            'example.com',
            '_dmarc.example.com',
            'TXT',
            '"v=DMARC1; p=reject; rua=mailto:dmarc@example.com"',
            0
        );

        $decoded = RecordIdentifier::decode($encoded);

        $this->assertEquals('_dmarc.example.com', $decoded['name']);
        $this->assertEquals('"v=DMARC1; p=reject; rua=mailto:dmarc@example.com"', $decoded['content']);
    }
}
