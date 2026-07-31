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

namespace Poweradmin\Tests\Unit\Application\Template;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\ValueObject\RecordIdentifier;

/**
 * A digit-only guard in editSelectedRecord() silently blocked every record edit
 * in API mode (#1415), so this pins the template pattern to the record IDs the
 * edit and delete controllers accept.
 */
class EditRecordIdGuardTest extends TestCase
{
    private const THEMES = ['default', 'modern'];

    /**
     * The guard must not be stricter than the server. Anything
     * EditRecordController and DeleteRecordController accept has to survive it,
     * otherwise the request is never made.
     */
    public function testGuardAcceptsEveryIdTheControllersAccept(): void
    {
        $ids = [
            '1',
            '42',
            '2147483647',
            RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0),
            RecordIdentifier::encode('example.com', 'example.com', 'MX', 'mail.example.com', 10),
            RecordIdentifier::encode(
                'example.com',
                '_dmarc.example.com',
                'TXT',
                '"v=DMARC1; p=reject; rua=mailto:dmarc@example.com"',
                0
            ),
            RecordIdentifier::encode('0.168.192.in-addr.arpa', '1.0.168.192.in-addr.arpa', 'PTR', 'host.example.com', 0),
        ];

        foreach (self::THEMES as $theme) {
            $pattern = $this->extractGuardPattern($theme);

            foreach ($ids as $id) {
                $this->assertTrue(
                    Validator::isNumber($id) || RecordIdentifier::isEncoded($id),
                    "Fixture $id is not an ID the controllers accept"
                );
                $this->assertMatchesRegularExpression(
                    $pattern,
                    $id,
                    "The $theme theme rejects the record ID $id"
                );
            }
        }
    }

    public function testGuardRejectsUnsupportedValues(): void
    {
        $rejected = ['', '../1', '1/edit', 'a b', "1\nX", '<script>', 'id=1&x=2'];

        foreach (self::THEMES as $theme) {
            $pattern = $this->extractGuardPattern($theme);

            foreach ($rejected as $id) {
                $this->assertDoesNotMatchRegularExpression($pattern, $id, "The $theme theme should reject $id");
            }
        }
    }

    /**
     * Pulls the record ID regex out of editSelectedRecord() and converts it to
     * a PCRE pattern. The JS literal uses no flags or JS-only syntax, so the
     * source translates directly.
     */
    private function extractGuardPattern(string $theme): string
    {
        $path = dirname(__DIR__, 4) . "/templates/$theme/edit.html";
        $this->assertFileExists($path);

        $template = file_get_contents($path);
        $this->assertIsString($template);

        $found = preg_match(
            '/function editSelectedRecord\(\).*?!\/(?P<guard>.+?)\/\.test\(selectedRecordId\)/s',
            $template,
            $matches
        );

        $this->assertSame(1, $found, "No record ID guard found in the $theme theme");

        return '/' . $matches['guard'] . '/';
    }
}
