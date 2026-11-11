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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\Internal\ValidationController;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

/**
 * The validate endpoint answers 400 for a body whose records or record fields are the wrong type.
 */
class ValidationControllerInputTypesTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedBodyProvider(): array
    {
        return [
            'records as a string' => [['records' => 'abc']],
            'name as an array' => [['name' => ['x'], 'type' => 'A', 'content' => '192.0.2.1']],
            'content as an array' => [['name' => 'www', 'type' => 'A', 'content' => ['x']]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('malformedBodyProvider')]
    public function testAMalformedBodyIs400(array $body): void
    {
        $controller = (new ReflectionClass(ValidationController::class))->newInstanceWithoutConstructor();
        $request = Request::create('/api/internal/validation', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string)json_encode($body));
        (new ReflectionProperty($controller, 'request'))->setValue($controller, $request);

        $response = (new ReflectionMethod($controller, 'validateRecord'))->invoke($controller);

        $this->assertSame(400, $response->getStatusCode());
    }
}
