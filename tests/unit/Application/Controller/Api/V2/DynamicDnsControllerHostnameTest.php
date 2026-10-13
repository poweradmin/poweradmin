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

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\DynamicDnsController;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DynamicDnsUpdateService;
use Poweradmin\Domain\Service\DynamicDnsValidationService;
use Poweradmin\Domain\ValueObject\HostnameValue;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The v2 dynamic DNS endpoint lowercases the hostname like dynamic_update.php does,
 * so a differently cased update finds the records an earlier one wrote (gh #1686).
 */
class DynamicDnsControllerHostnameTest extends TestCase
{
    public function testTheHostnameIsLowercasedBeforeTheUpdate(): void
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);

        $update = $this->createMock(DynamicDnsUpdateService::class);
        $update->expects($this->once())
            ->method('applyForUser')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn(HostnameValue $hostname): bool => $hostname->getValue() === 'host.example.com')
            )
            ->willReturn(['status' => 'good', 'zone_id' => 3, 'applied_ipv4' => ['192.0.2.1'], 'applied_ipv6' => [], 'changed' => true]);

        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('admin');
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($statement);

        $request = Request::create('/api/v2/dynamic-dns', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'hostname' => 'Host.Example.COM',
            'ipv4' => '192.0.2.1',
        ]));

        $controller = (new ReflectionClass(DynamicDnsController::class))->newInstanceWithoutConstructor();
        $properties = [
            'request' => $request,
            'db' => $db,
            'permissionService' => $permissions,
            'updateService' => $update,
            'validationService' => new DynamicDnsValidationService($this->createMock(ConfigurationManager::class)),
            'authenticatedUserId' => 1,
            'apiKeyScope' => ApiKeyScope::unrestricted(),
        ];
        foreach ($properties as $name => $value) {
            (new ReflectionProperty(DynamicDnsController::class, $name))->setValue($controller, $value);
        }

        /** @var JsonResponse $response */
        $response = (new ReflectionMethod(DynamicDnsController::class, 'updateRecord'))->invoke($controller);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('host.example.com', json_decode((string) $response->getContent(), true)['data']['hostname']);
    }
}
