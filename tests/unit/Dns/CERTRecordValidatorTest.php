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
use Poweradmin\Domain\Service\DnsValidation\CERTRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the CERTRecordValidator
 */
class CERTRecordValidatorTest extends TestCase
{
    private CERTRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new CERTRecordValidator($this->configMock);
    }

    public function testValidateWithValidNumericData()
    {
        // Using now-deprecated algorithm RSAMD5 (1) to check warnings
        $content = '1 12345 1 MIIC+zCCAeOgAwIBAgIJAJl8';  // Shortened cert data for test
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);

        // Check for warnings
        $this->assertTrue($result->hasWarnings());
        $this->assertIsArray($result->getWarnings());
        $this->assertNotEmpty($result->getWarnings());

        // Check for specific warning about the deprecated algorithm
        $deprecatedAlgorithmFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'MUST NOT be used') !== false) {
                $deprecatedAlgorithmFound = true;
                break;
            }
        }
        $this->assertTrue($deprecatedAlgorithmFound, 'Should warn about deprecated algorithm usage (RSAMD5)');
    }

    public function testValidateWithValidMnemonicData()
    {
        $content = 'PKIX 12345 RSASHA1 MIIC+zCCAeOgAwIBAgIJAJl8';  // Shortened cert data for test
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);

        // Check for warnings
        $this->assertTrue($result->hasWarnings());
        $this->assertIsArray($result->getWarnings());
        $this->assertNotEmpty($result->getWarnings());

        // Check for specific warning about the not recommended algorithm
        $notRecommendedFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'NOT RECOMMENDED') !== false) {
                $notRecommendedFound = true;
                break;
            }
        }
        $this->assertTrue($notRecommendedFound, 'Should warn about not recommended algorithm usage (RSASHA1)');
    }

    public function testValidateWithInvalidType()
    {
        $content = '66000 12345 5 MIIC+zCCAeOgAwIBAgIJAJl8';  // Type > 65535
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTypeMnemonic()
    {
        $content = 'INVALID 12345 5 MIIC+zCCAeOgAwIBAgIJAJl8';  // Invalid type mnemonic
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidKeyTag()
    {
        $content = '1 -1 5 MIIC+zCCAeOgAwIBAgIJAJl8';  // Key tag < 0
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidAlgorithm()
    {
        $content = '1 12345 256 MIIC+zCCAeOgAwIBAgIJAJl8';  // Algorithm > 255
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidAlgorithmMnemonic()
    {
        $content = '1 12345 INVALID MIIC+zCCAeOgAwIBAgIJAJl8';  // Invalid algorithm mnemonic
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidCertificateData()
    {
        $content = '1 12345 5 @@invalid base64**';  // Invalid base64
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidFormat()
    {
        $content = '1 12345 5';  // Missing certificate data
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidHostname()
    {
        $content = '1 12345 5 MIIC+zCCAeOgAwIBAgIJAJl8';
        $name = '-invalid-hostname.example.com';  // Invalid hostname
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithInvalidTTL()
    {
        $content = '1 12345 5 MIIC+zCCAeOgAwIBAgIJAJl8';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = -1;  // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testValidateWithDefaultTTL()
    {
        $content = '1 12345 5 MIIC+zCCAeOgAwIBAgIJAJl8';
        $name = 'host.example.com';
        $prio = 0;
        $ttl = '';  // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    public function testValidateWithURLBasedType()
    {
        $content = 'IPKIX 12345 13 https://example.com/cert.pem';  // URL-based type
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();

        // Check for warnings about URL-based types
        $urlWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'URL-based certificate types') !== false) {
                $urlWarningFound = true;
                break;
            }
        }
        $this->assertTrue($urlWarningFound, 'Should warn about security implications of URL-based types');
    }

    public function testValidateWithInvalidURLInURLBasedType()
    {
        $content = 'IPKIX 12345 13 not-a-valid-url';  // Invalid URL for URL-based type
        $name = 'host.example.com';
        $prio = 0;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
    }
}
