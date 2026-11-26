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

namespace Poweradmin\Tests\Unit\Dns;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\MXRecordValidator;
use Poweradmin\Domain\Service\ZoneTemplateRecordValidationService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * The MX/SRV priority checks share one 0-65535 rule: plain digits only, no exponent or decimal form.
 */
class RecordPriorityNumericFormTest extends TestCase
{
    /** @return array<string, array{mixed}> */
    public static function nonDigitProvider(): array
    {
        return ['exponent' => ['1e3'], 'decimal' => ['10.5'], 'float' => [10.5], 'hex' => ['0x10'], 'over' => [65536]];
    }

    private function mxValidator(): MXRecordValidator
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn('example.com');

        return new MXRecordValidator($config);
    }

    private function commonValidator(): DnsCommonValidator
    {
        return new DnsCommonValidator($this->createMock(PDO::class), $this->createMock(ConfigurationInterface::class));
    }

    #[DataProvider('nonDigitProvider')]
    public function testMxValidatorRefusesNonDigitForms(mixed $prio): void
    {
        $this->assertFalse($this->mxValidator()->validate('mail.example.com', 'example.com', $prio, 3600, 86400)->isValid());
    }

    #[DataProvider('nonDigitProvider')]
    public function testCommonValidatorRefusesNonDigitForms(mixed $prio): void
    {
        $this->assertFalse($this->commonValidator()->validatePriority($prio, 'MX')->isValid());
        $this->assertFalse($this->commonValidator()->validatePriority($prio, 'SRV')->isValid());
        $this->assertFalse($this->commonValidator()->isValidPriority($prio));
    }

    public function testBothValidatorsAcceptPlainAndPaddedPriorities(): void
    {
        foreach ([0, 65535, '20', ' 20 '] as $prio) {
            $this->assertTrue($this->mxValidator()->validate('mail.example.com', 'example.com', $prio, 3600, 86400)->isValid());
            $this->assertTrue($this->commonValidator()->validatePriority($prio, 'MX')->isValid());
            $this->assertTrue($this->commonValidator()->isValidPriority($prio));
        }
        $this->assertSame(20, $this->commonValidator()->validatePriority(' 20 ', 'MX')->getData());
    }

    #[DataProvider('nonDigitProvider')]
    public function testTemplateRecordWithAnUnresolvedPlaceholderRefusesNonDigitPriority(mixed $prio): void
    {
        $config = ConfigurationManager::getInstance();
        $config->initialize();
        $service = new ZoneTemplateRecordValidationService(new DnsValidatorRegistry($config, $this->createMock(PDO::class)));

        $result = $service->validate('[UNKNOWN]', 'MX', 'mail.example.com', 3600, $prio, 3600);

        $this->assertFalse($result->isValid());
    }
}
