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

namespace Poweradmin\Tests\Unit\Infrastructure\Utility;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\ProtocolDetector;

class ProtocolDetectorTest extends TestCase
{
    public function testDetectsHttpsWhenServerVariableIsOn(): void
    {
        $detector = new ProtocolDetector(['HTTPS' => 'on']);
        $this->assertEquals('https', $detector->detect());
    }

    public function testDetectsHttpsWhenServerVariableIsOne(): void
    {
        $detector = new ProtocolDetector(['HTTPS' => '1']);
        $this->assertEquals('https', $detector->detect());
    }

    public function testDetectsHttpWhenServerVariableIsOff(): void
    {
        $detector = new ProtocolDetector(['HTTPS' => 'off']);
        $this->assertEquals('http', $detector->detect());
    }

    public function testDetectsHttpsFromForwardedProtoHeader(): void
    {
        $detector = new ProtocolDetector(['HTTP_X_FORWARDED_PROTO' => 'https']);
        $this->assertEquals('https', $detector->detect());
    }

    public function testDetectsHttpFromForwardedProtoHeader(): void
    {
        $detector = new ProtocolDetector(['HTTP_X_FORWARDED_PROTO' => 'http']);
        $this->assertEquals('http', $detector->detect());
    }

    public function testForwardedProtoWorksWhenHttpsIsOff(): void
    {
        $detector = new ProtocolDetector([
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https'
        ]);
        $this->assertEquals('https', $detector->detect());
    }

    public function testHttpsServerVariableTakesPrecedenceOverForwardedProto(): void
    {
        $detector = new ProtocolDetector([
            'HTTPS' => 'on',
            'HTTP_X_FORWARDED_PROTO' => 'http'
        ]);
        $this->assertEquals('https', $detector->detect());
    }

    public function testDetectsHttpsFromPort443(): void
    {
        $detector = new ProtocolDetector(['SERVER_PORT' => '443']);
        $this->assertEquals('https', $detector->detect());
    }

    public function testDetectsHttpFromPort80(): void
    {
        $detector = new ProtocolDetector(['SERVER_PORT' => '80']);
        $this->assertEquals('http', $detector->detect());
    }

    public function testDefaultsToHttpWhenNoIndicators(): void
    {
        $detector = new ProtocolDetector([]);
        $this->assertEquals('http', $detector->detect());
    }

    public function testIsSecureReturnsTrueForHttps(): void
    {
        $detector = new ProtocolDetector(['HTTPS' => 'on']);
        $this->assertTrue($detector->isSecure());
    }

    public function testIsSecureReturnsFalseForHttp(): void
    {
        $detector = new ProtocolDetector([]);
        $this->assertFalse($detector->isSecure());
    }

    public function testIsSecureReturnsTrueForForwardedProtoHttps(): void
    {
        $detector = new ProtocolDetector(['HTTP_X_FORWARDED_PROTO' => 'https']);
        $this->assertTrue($detector->isSecure());
    }

    public function testUsesGlobalServerWhenNullPassed(): void
    {
        // Backup and set global
        $backup = $_SERVER;
        $_SERVER['HTTPS'] = 'on';

        try {
            $detector = new ProtocolDetector();
            $this->assertEquals('https', $detector->detect());
        } finally {
            // Restore
            $_SERVER = $backup;
        }
    }
}
