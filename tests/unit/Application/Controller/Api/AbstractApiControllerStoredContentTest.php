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
namespace Poweradmin\Tests\Unit\Application\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\AbstractApiController;

/**
 * An API update that leaves content out reuses the stored content, which may predate the
 * control character check, so it is cleaned before the write is validated.
 */
#[CoversClass(AbstractApiController::class)]
class AbstractApiControllerStoredContentTest extends TestCase
{
    private function updated(array $input, string $content): string
    {
        $controller = new class extends AbstractApiController {
            // Skip the bootstrap (config, DB, API key auth).
            public function __construct()
            {
            }

            public function run(): void
            {
            }

            public function updated(array $input, string $content): string
            {
                return $this->updatedContent($input, $content);
            }
        };

        return $controller->updated($input, $content);
    }

    public static function storedContent(): array
    {
        return [
            'clean content is kept' => ['mail.example.com.', 'mail.example.com.'],
            'a C0 control becomes a space' => ["v=spf1\x01-all", 'v=spf1 -all'],
            'a CRLF run becomes one space' => ["line one\r\nline two", 'line one line two'],
            'a C1 control becomes a space' => ["a\u{0085}b", 'a b'],
            'the first C1 control becomes a space' => ["a\u{0080}b", 'a b'],
            'the last C1 control becomes a space' => ["a\u{009F}b", 'a b'],
            'DEL becomes a space' => ["a\x7Fb", 'a b'],
            'edge controls are trimmed' => ["\x01text\x1F", 'text'],
            'non-ASCII text is kept' => ['ąžuolas', 'ąžuolas'],
        ];
    }

    #[DataProvider('storedContent')]
    public function testStoredContentLosesItsControlCharacters(string $stored, string $expected): void
    {
        $this->assertSame($expected, $this->updated([], $stored));
    }

    public function testStoredContentWithBytesThatAreNotUtf8IsNotEmptied(): void
    {
        // Without the scrub the /u pattern fails on invalid UTF-8 and the content comes back empty
        $healed = $this->updated([], "abc\x01\xFFdef");

        $this->assertTrue(mb_check_encoding($healed, 'UTF-8'));
        $this->assertStringStartsWith('abc ', $healed);
        $this->assertStringEndsWith('def', $healed);
        $this->assertStringNotContainsString("\xFF", $healed);
    }

    public function testSubmittedContentIsOnlyTrimmedSoValidationStillRefusesItsControls(): void
    {
        $this->assertSame("v=spf1\x01-all", $this->updated(['content' => "v=spf1\x01-all"], " v=spf1\x01-all "));
    }
}
