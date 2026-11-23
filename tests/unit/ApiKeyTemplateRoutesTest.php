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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The regenerate form and the edit page link must point at the route that exists,
 * /settings/api-keys/{id}/regenerate, in every theme.
 */
class ApiKeyTemplateRoutesTest extends TestCase
{
    public static function themes(): array
    {
        return [['default'], ['modern']];
    }

    #[DataProvider('themes')]
    public function testRegenerateFormPostsToTheRegenerateRoute(string $theme): void
    {
        $html = (string) file_get_contents(dirname(__DIR__, 2) . "/templates/{$theme}/api_key_regenerate.html");

        $this->assertStringContainsString('method="post" action="{{ base_url_prefix }}/settings/api-keys/{{ api_key.id }}/regenerate"', $html);
    }

    #[DataProvider('themes')]
    public function testEditPageLinksToTheRegenerateRoute(string $theme): void
    {
        $html = (string) file_get_contents(dirname(__DIR__, 2) . "/templates/{$theme}/api_key_edit.html");

        $this->assertStringContainsString('href="{{ base_url_prefix }}/settings/api-keys/{{ api_key.id }}/regenerate"', $html);
        $this->assertStringNotContainsString('href="{{ base_url_prefix }}/settings/api-keys/{{ api_key.id }}"', $html);
    }
}
