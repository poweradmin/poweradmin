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
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\AbstractApiController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Stored text that is not valid UTF-8 is substituted in the body instead of failing the response.
 */
#[CoversClass(AbstractApiController::class)]
class AbstractApiControllerJsonResponseTest extends TestCase
{
    private function respond(mixed $data, int $status = 200): JsonResponse
    {
        $controller = new class extends AbstractApiController {
            // Skip the bootstrap (config, DB, API key auth).
            public function __construct()
            {
            }

            public function run(): void
            {
            }

            public function respond(mixed $data, int $status): JsonResponse
            {
                return $this->returnJsonResponse($data, $status);
            }
        };

        return $controller->respond($data, $status);
    }

    public function testInvalidUtf8IsSubstituted(): void
    {
        $response = $this->respond(['description' => "ab\xFF"]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"description":"ab' . chr(92) . 'ufffd"}', $response->getContent());
    }

    public function testDefaultEscapingIsKept(): void
    {
        $response = $this->respond(['a' => "<b>&'\""]);

        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
        $expected = json_encode(['a' => "<b>&'\""], $flags);
        $this->assertSame($expected, $response->getContent());
    }

    public function testNoContentStaysEmpty(): void
    {
        $response = $this->respond(null, 204);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }
}
