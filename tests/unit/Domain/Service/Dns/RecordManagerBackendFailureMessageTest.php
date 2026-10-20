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

namespace Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\RecordFormFieldPresenter;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Validation\RecordField;

/**
 * A record write PowerDNS refuses names PowerDNS's reason after the generic text.
 */
class RecordManagerBackendFailureMessageTest extends TestCase
{
    private const GENERIC = 'Failed to add record to DNS backend.';

    #[Test]
    public function testReasonIsAppendedAfterTheMessageWithoutItsFullStop(): void
    {
        $this->assertSame(
            'Failed to add record to DNS backend: Not in expected format',
            RecordManager::backendFailureMessage(self::GENERIC, 'Not in expected format')
        );
    }

    #[Test]
    public function testMissingReasonKeepsTheGenericMessage(): void
    {
        $this->assertSame(self::GENERIC, RecordManager::backendFailureMessage(self::GENERIC, null));
    }

    #[Test]
    public function testBlankReasonKeepsTheGenericMessage(): void
    {
        $this->assertSame(self::GENERIC, RecordManager::backendFailureMessage(self::GENERIC, " \t\n "));
    }

    #[Test]
    public function testLongReasonIsCapped(): void
    {
        $message = RecordManager::backendFailureMessage(self::GENERIC, str_repeat('x', 1000));

        $this->assertSame('Failed to add record to DNS backend: ' . str_repeat('x', 300) . '...', $message);
    }

    #[Test]
    public function testControlCharactersAreReplacedWithSpaces(): void
    {
        $message = RecordManager::backendFailureMessage(self::GENERIC, "bad\r\ncontent\x00here\x1b[0m");

        $this->assertSame('Failed to add record to DNS backend: bad content here [0m', $message);
    }

    #[Test]
    public function testIdeographicFullStopOfTranslatedPrefixIsDropped(): void
    {
        $this->assertSame('Failed: reason', RecordManager::backendFailureMessage("Failed\u{3002}", 'reason'));
        $this->assertSame('Failed: reason', RecordManager::backendFailureMessage("Failed\u{FF0E} ", 'reason'));
    }

    #[Test]
    public function testBackendFailureNamesTheContentFieldSoTheReasonIsNotGuessedAt(): void
    {
        $result = RecordWriteResult::backendFailure('Failed to add record to DNS backend: bad name', RecordField::CONTENT);

        $this->assertSame('content', RecordFormFieldPresenter::fieldId($result->field, (string)$result->message));
    }

    #[Test]
    public function testMarkupIsLeftForTheTemplateToEscape(): void
    {
        $message = RecordManager::backendFailureMessage(self::GENERIC, "TXT '<b>\"x\"</b>'");

        $this->assertSame("Failed to add record to DNS backend: TXT '<b>\"x\"</b>'", $message);
    }
}
