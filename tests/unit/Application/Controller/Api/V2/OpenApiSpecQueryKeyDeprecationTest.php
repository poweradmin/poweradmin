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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use OpenApi\Generator;
use PHPUnit\Framework\TestCase;

class OpenApiSpecQueryKeyDeprecationTest extends TestCase
{
    public function testQueryApiKeySchemeIsMarkedDeprecatedAndPointsAtTheHeader(): void
    {
        $file = dirname(__DIR__, 6) . '/lib/Application/Controller/Api/V2/OpenApiSpec.php';
        $spec = json_decode((new Generator())->generate([$file], validate: false)->toJson(), true);

        $scheme = $spec['components']['securitySchemes']['apiKeyQuery'];
        $this->assertSame('api_key', $scheme['name']);
        $this->assertSame('query', $scheme['in']);
        $this->assertTrue($scheme['x-deprecated'] ?? $scheme['deprecated'] ?? false);
        $this->assertStringContainsString('X-API-Key header', $scheme['description']);
    }
}
