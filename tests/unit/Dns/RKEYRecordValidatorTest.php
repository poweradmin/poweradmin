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

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\RKEYRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the RKEYRecordValidator
 */
class RKEYRecordValidatorTest extends TestCase
{
    private RKEYRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new RKEYRecordValidator($this->configMock);
    }

    public function testValidateWithValidData()
    {
        $content = '256 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);

        $this->assertEquals($name, $data['name']);
        $data = $result->getData();

        $this->assertEquals(0, $data['prio']);

        $this->assertEquals(3600, $data['ttl']);
    }

    public function testValidateWithDifferentFlagsValue()
    {
        $content = '257 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithDifferentProtocolValue()
    {
        $content = '256 2 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithDifferentAlgorithmValue()
    {
        $content = '256 3 5 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals($content, $data['content']);
    }

    public function testValidateWithEmptyContent()
    {
        $content = '';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidFlags()
    {
        $content = 'invalid 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidProtocol()
    {
        $content = '256 invalid 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = '256 3 invalid AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithMissingPublicKeyData()
    {
        $content = '256 3 7';  // Missing public key data
        $name = 'example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '256 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '256 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());


        $this->assertEmpty($result->getErrors());
        $data = $result->getData();

        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '256 3 7 AwEAAcFPJsUwFgdRmBwNP8XU5Zn/2Vaco9SICUyPxbQzD2WFLpSJ93eSVNRIm/KF6lX7nfR/nIiVY5VZ9xbo55f3F99OnKKTyMbV6FfX/qZu3RQ83An0K1JFJwbQrX7TAXd6FVWjOw==';
        $name = 'invalid hostname with spaces';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }
}
