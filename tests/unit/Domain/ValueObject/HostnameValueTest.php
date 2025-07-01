<?php

namespace unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\ValueObject\HostnameValue;
use InvalidArgumentException;

class HostnameValueTest extends TestCase
{
    public function testValidHostnameCreation(): void
    {
        $hostname = new HostnameValue('example.com');
        $this->assertEquals('example.com', $hostname->getValue());
        $this->assertEquals('example.com', (string)$hostname);
    }

    public function testValidSubdomainCreation(): void
    {
        $hostname = new HostnameValue('sub.example.com');
        $this->assertEquals('sub.example.com', $hostname->getValue());
    }

    public function testValidDeeplyNestedSubdomain(): void
    {
        $hostname = new HostnameValue('a.b.c.d.example.com');
        $this->assertEquals('a.b.c.d.example.com', $hostname->getValue());
    }

    public function testEmptyHostnameThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Hostname cannot be empty');
        new HostnameValue('');
    }

    public function testTooLongHostnameThrowsException(): void
    {
        $longHostname = str_repeat('a', 254);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Hostname too long (max 253 characters)');
        new HostnameValue($longHostname);
    }

    public function testHostnameWithInvalidCharactersThrowsException(): void
    {
        $invalidHostnames = [
            'host<name.com',
            'host>name.com',
            'host"name.com',
            "host'name.com",
            'host;name.com',
            "host\x00name.com",
            "host\x1fname.com",
        ];

        foreach ($invalidHostnames as $invalidHostname) {
            try {
                new HostnameValue($invalidHostname);
                $this->fail("Expected InvalidArgumentException for hostname: {$invalidHostname}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Hostname contains invalid characters', $e->getMessage());
            }
        }
    }

    public function testInvalidHostnameFormatThrowsException(): void
    {
        $invalidFormats = [
            '-example.com',
            'example-.com',
            '.example.com',
            'example.com.',
            'example..com',
            'ex ample.com',
        ];

        foreach ($invalidFormats as $invalidFormat) {
            try {
                new HostnameValue($invalidFormat);
                $this->fail("Expected InvalidArgumentException for hostname format: {$invalidFormat}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Hostname format is invalid', $e->getMessage());
            }
        }
    }

    public function testValidSingleLabelHostname(): void
    {
        $hostname = new HostnameValue('localhost');
        $this->assertEquals('localhost', $hostname->getValue());
    }

    public function testValidNumericLabels(): void
    {
        $hostname = new HostnameValue('123.456.example.com');
        $this->assertEquals('123.456.example.com', $hostname->getValue());
    }

    public function testValidMixedAlphanumeric(): void
    {
        $hostname = new HostnameValue('test123.example456.com');
        $this->assertEquals('test123.example456.com', $hostname->getValue());
    }

    public function testValidWithHyphens(): void
    {
        $hostname = new HostnameValue('test-host.example-domain.com');
        $this->assertEquals('test-host.example-domain.com', $hostname->getValue());
    }

    public function testEquals(): void
    {
        $hostname1 = new HostnameValue('example.com');
        $hostname2 = new HostnameValue('example.com');
        $hostname3 = new HostnameValue('different.com');

        $this->assertTrue($hostname1->equals($hostname2));
        $this->assertFalse($hostname1->equals($hostname3));
    }


    public function testMaximumValidLength(): void
    {
        $label1 = str_repeat('a', 63);
        $label2 = str_repeat('b', 63);
        $label3 = str_repeat('c', 63);
        $label4 = str_repeat('d', 61);
        $maxLengthHostname = $label1 . '.' . $label2 . '.' . $label3 . '.' . $label4;
        $this->assertEquals(253, strlen($maxLengthHostname));

        $hostname = new HostnameValue($maxLengthHostname);
        $this->assertEquals($maxLengthHostname, $hostname->getValue());
    }

    public function testValidLabelLengths(): void
    {
        $validHostname = str_repeat('a', 63) . '.example.com';
        $hostname = new HostnameValue($validHostname);
        $this->assertEquals($validHostname, $hostname->getValue());
    }
}
