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

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\DnsValidation\MINFORecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class MINFORecordValidatorTest extends BaseDnsTest
{
    private MINFORecordValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                if ($section === 'dns') {
                    if ($key === 'top_level_tld_check') {
                        return false;
                    }
                    if ($key === 'strict_tld_check') {
                        return false;
                    }
                }
                return null;
            });
        $this->validator = new MINFORecordValidator($configMock);
    }

    public function testValidMINFORecord()
    {
        $content = 'responsible.example.com errors.example.com';
        $name = 'example.com';
        $prio = null; // MINFO doesn't use priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']); // MINFO sets priority to 0
        $this->assertEquals($ttl, $data['ttl']);

        // Check that warnings are included
        $this->assertTrue($result->hasWarnings());
        $this->assertIsArray($result->getWarnings());
        $this->assertGreaterThan(0, count($result->getWarnings()));

        // Check for warning about experimental status
        $foundExperimentalWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'EXPERIMENTAL') !== false) {
                $foundExperimentalWarning = true;
                break;
            }
        }
        $this->assertTrue($foundExperimentalWarning, 'Warning about experimental status not found');

        // Check for warning about -request convention
        $foundRequestWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'request') !== false) {
                $foundRequestWarning = true;
                break;
            }
        }
        $this->assertTrue($foundRequestWarning, 'Warning about -request naming convention not found');
    }

    public function testInvalidContent()
    {
        $content = 'responsible.example.com'; // Missing error mailbox
        $name = 'example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testInvalidResponsibleMailbox()
    {
        $content = '-invalid-.example.com errors.example.com';
        $name = 'example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testInvalidErrorMailbox()
    {
        $content = 'responsible.example.com -invalid-.example.com';
        $name = 'example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testInvalidDomainName()
    {
        $content = 'responsible.example.com errors.example.com';
        $name = '-invalid-.example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testEmptyContent()
    {
        $content = '';
        $name = 'example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testInvalidTTL()
    {
        $content = 'responsible.example.com errors.example.com';
        $name = 'example.com';
        $prio = null;
        $ttl = -1; // Invalid negative TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());


        $this->assertNotEmpty($result->getErrors());
    }

    public function testDefaultTTL()
    {
        $content = 'responsible.example.com errors.example.com';
        $name = 'example.com';
        $prio = null;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());

        $data = $result->getData();
        $this->assertEquals($defaultTTL, $data['ttl']);
    }

    public function testWithRootDomainRMAILBX()
    {
        $content = '. errors.example.com'; // Root domain for RMAILBX
        $name = 'example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());

        $data = $result->getData();

        // Check for warning about root domain in RMAILBX
        $foundRootWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'owner of the MINFO record is responsible') !== false) {
                $foundRootWarning = true;
                break;
            }
        }
        $this->assertTrue($foundRootWarning, 'Warning about root domain in RMAILBX not found');
    }

    public function testWithRootDomainEMAILBX()
    {
        $content = 'responsible.example.com .'; // Root domain for EMAILBX
        $name = 'example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());

        $data = $result->getData();

        // Check for warning about root domain in EMAILBX
        $foundRootWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'errors should be returned to the sender') !== false) {
                $foundRootWarning = true;
                break;
            }
        }
        $this->assertTrue($foundRootWarning, 'Warning about root domain in EMAILBX not found');
    }

    public function testWithRequestNamingConvention()
    {
        $content = 'mailing-list-request.example.com errors.example.com'; // Using -request naming
        $name = 'mailing-list.example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());

        $data = $result->getData();

        // Should NOT have warning about -request convention
        $foundRequestWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'mailbox "list-name-request"') !== false) {
                $foundRequestWarning = true;
                break;
            }
        }
        $this->assertFalse($foundRequestWarning, 'Warning about -request naming convention should not be present');
    }

    public function testWithIdenticalMailboxes()
    {
        $content = 'admin.example.com admin.example.com'; // Same mailbox for both fields
        $name = 'example.com';
        $prio = null;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());

        $data = $result->getData();

        // Check for warning about identical mailboxes
        $foundIdenticalWarning = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'identical') !== false) {
                $foundIdenticalWarning = true;
                break;
            }
        }
        $this->assertTrue($foundIdenticalWarning, 'Warning about identical mailboxes not found');
    }
}
