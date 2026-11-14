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
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationService;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use TestHelpers\FakeConfiguration;
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

    public function testARelativeNameIsValidatedInsideTheAuthorisedZone(): void
    {
        $controller = (new ReflectionClass(ValidationController::class))->newInstanceWithoutConstructor();
        $request = Request::create('/api/internal/validation', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string)json_encode(['zone_id' => 7, 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.1']));

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->with(7)->willReturn('example.com');
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('domainRepository')->willReturn($domains);
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canViewZone')->willReturn(true);
        $user = $this->createMock(UserContextService::class);
        $user->method('getLoggedInUserId')->willReturn(1);
        $validation = $this->createMock(DnsRecordValidationService::class);
        $validation->expects($this->once())->method('validateRecord')
            ->with(0, 7, 'A', '192.0.2.1', 'www.example.com')
            ->willReturn(ValidationResult::success([]));

        $set = static function (string $class, string $property, mixed $value) use ($controller): void {
            (new ReflectionProperty($class, $property))->setValue($controller, $value);
        };
        $set(ValidationController::class, 'request', $request);
        $set(BaseController::class, 'serviceFactory', $factory);
        $set(BaseController::class, 'config', new FakeConfiguration([]));
        $set(ValidationController::class, 'validationService', $validation);
        $set(ValidationController::class, 'apiPermissionService', $permissions);
        $set(ValidationController::class, 'userContextService', $user);

        $response = (new ReflectionMethod($controller, 'validateRecord'))->invoke($controller);

        $this->assertSame(200, $response->getStatusCode());
    }
}
