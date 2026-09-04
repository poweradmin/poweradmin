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

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Logger\PhpErrorLogPsrLogger;

class PhpErrorLogPsrLoggerTest extends TestCase
{
    private string $logFile;
    private string $originalErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'poweradmin-error-log-');
        $this->originalErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->originalErrorLog);
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
        parent::tearDown();
    }

    public function testWarningIsWrittenToErrorLog(): void
    {
        $logger = new PhpErrorLogPsrLogger();
        $logger->warning('Poweradmin: misconfiguration detected');

        $contents = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('Poweradmin: misconfiguration detected', $contents);
    }

    public function testPsr3PlaceholdersAreInterpolated(): void
    {
        $logger = new PhpErrorLogPsrLogger();
        $logger->warning(
            'dns.default_zone_template = "{name}" matches {count} templates',
            ['name' => 'Standard', 'count' => 2]
        );

        $contents = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('dns.default_zone_template = "Standard" matches 2 templates', $contents);
    }

    public function testNonScalarContextValuesAreSkipped(): void
    {
        $logger = new PhpErrorLogPsrLogger();
        $logger->info('payload: {data}', ['data' => ['unused' => true]]);

        $contents = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('payload: {data}', $contents);
    }
}
