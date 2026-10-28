<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit\Application\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\ResponseHeaders;

class ResponseHeadersTest extends TestCase
{
    public function testDefaultSetDeniesFraming(): void
    {
        $built = ResponseHeaders::build([]);

        $this->assertSame([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'; object-src 'none'; base-uri 'self'",
        ], $built['headers']);
        $this->assertSame([], $built['rejected']);
    }

    public function testValidOriginsReplaceTheDenyAndDropXFrameOptions(): void
    {
        $built = ResponseHeaders::build(['https://portal.example.com', 'http://10.0.0.5:8443', 'https://*.example.org', 'https://portal.example.com']);

        $this->assertSame(
            "frame-ancestors 'self' https://portal.example.com http://10.0.0.5:8443 https://*.example.org; object-src 'none'; base-uri 'self'",
            $built['headers']['Content-Security-Policy']
        );
        $this->assertArrayNotHasKey('X-Frame-Options', $built['headers']);
        $this->assertSame('nosniff', $built['headers']['X-Content-Type-Options']);
        $this->assertSame([], $built['rejected']);
    }

    #[DataProvider('invalidOrigins')]
    public function testInvalidOriginsAreIgnored(mixed $origin): void
    {
        $built = ResponseHeaders::build([$origin]);

        $this->assertSame("frame-ancestors 'none'; object-src 'none'; base-uri 'self'", $built['headers']['Content-Security-Policy']);
        $this->assertSame('DENY', $built['headers']['X-Frame-Options']);
        $this->assertCount(1, $built['rejected']);
    }

    public static function invalidOrigins(): array
    {
        return [
            'semicolon' => ['https://a.example.com; script-src *'],
            'space' => ['https://a.example.com https://b.example.com'],
            'quote' => ["https://a.example.com'"],
            'keyword' => ["'none'"],
            'wildcard' => ['*'],
            'no scheme' => ['portal.example.com'],
            'path' => ['https://a.example.com/path'],
            'newline' => ["https://a.example.com\r\nSet-Cookie: x=1"],
            'non string' => [42],
        ];
    }

    public function testOriginsAreLowercased(): void
    {
        $built = ResponseHeaders::build(['HTTPS://Portal.Example.COM']);

        $this->assertSame("frame-ancestors 'self' https://portal.example.com; object-src 'none'; base-uri 'self'", $built['headers']['Content-Security-Policy']);
        $this->assertSame([], $built['rejected']);
    }

    public function testAValidOriginSurvivesBesideAnInvalidOne(): void
    {
        $built = ResponseHeaders::build(['https://ok.example.com', 'bad;origin']);

        $this->assertSame("frame-ancestors 'self' https://ok.example.com; object-src 'none'; base-uri 'self'", $built['headers']['Content-Security-Policy']);
        $this->assertSame(['bad;origin'], $built['rejected']);
    }

    public function testDefaultsAreSkippedOnceTheConfiguredSetWasSent(): void
    {
        $config = $this->createMock(\Poweradmin\Domain\Config\ConfigurationInterface::class);
        $config->method('get')->willReturn(['https://ok.example.com']);

        ResponseHeaders::resetForTests();
        ResponseHeaders::send($config, new \Psr\Log\NullLogger());
        $flag = new \ReflectionProperty(ResponseHeaders::class, 'sent');
        $this->assertTrue($flag->getValue());

        // Would otherwise reach emit(); the flag keeps the configured set in place
        ResponseHeaders::sendDefaults();
        $this->assertTrue($flag->getValue());

        ResponseHeaders::resetForTests();
        $this->assertFalse($flag->getValue());
    }

    public function testEachHeaderNameAppearsOnce(): void
    {
        $built = ResponseHeaders::build(['https://ok.example.com']);

        $this->assertSame(array_keys($built['headers']), array_unique(array_keys($built['headers'])));
    }
}
