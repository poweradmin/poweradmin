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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Controller\Api\V2\GroupZonesController;
use Poweradmin\Application\Controller\Api\V2\ZoneOwnersController;
use Poweradmin\Application\Controller\Api\V2\ZonesChangeRequestsController;
use Poweradmin\Domain\Model\ApiKeyScope;
use Symfony\Component\HttpFoundation\Request;

/**
 * Changing who may edit a zone, or asking for changes to it, is an update: a key limited to
 * create or delete must not reach these writes through the verb alone.
 */
class AccessChangingOperationsTest extends TestCase
{
    /** @return array<string, array{class-string<PublicApiController>, string, string[]}> */
    public static function mappings(): array
    {
        return [
            'owners add' => [ZoneOwnersController::class, 'POST', [ApiKeyScope::OP_UPDATE]],
            'owners remove' => [ZoneOwnersController::class, 'DELETE', [ApiKeyScope::OP_UPDATE, ApiKeyScope::OP_DELETE]],
            'owners list' => [ZoneOwnersController::class, 'GET', [ApiKeyScope::OP_VIEW]],
            'group zones assign' => [GroupZonesController::class, 'POST', [ApiKeyScope::OP_UPDATE]],
            'group zones unassign' => [GroupZonesController::class, 'DELETE', [ApiKeyScope::OP_UPDATE, ApiKeyScope::OP_DELETE]],
            'group zones list' => [GroupZonesController::class, 'GET', [ApiKeyScope::OP_VIEW]],
            'change request filing' => [ZonesChangeRequestsController::class, 'POST', [ApiKeyScope::OP_UPDATE]],
        ];
    }

    /**
     * @param class-string<PublicApiController> $class
     * @param string[] $expected
     */
    #[DataProvider('mappings')]
    public function testRequiredOperations(string $class, string $method, array $expected): void
    {
        $this->assertSame($expected, $this->operationsFor($class, $method));
    }

    public function testCreateOnlyAndDeleteOnlyKeysAreRefused(): void
    {
        $createOnly = new ApiKeyScope(null, [ApiKeyScope::OP_CREATE], false);
        $deleteOnly = new ApiKeyScope(null, [ApiKeyScope::OP_DELETE], false);

        foreach ([[ZoneOwnersController::class, 'POST'], [GroupZonesController::class, 'POST'], [ZonesChangeRequestsController::class, 'POST']] as [$class, $method]) {
            $this->assertFalse($this->allows($createOnly, $this->operationsFor($class, $method)), $class);
        }
        foreach ([[ZoneOwnersController::class, 'DELETE'], [GroupZonesController::class, 'DELETE']] as [$class, $method]) {
            $this->assertFalse($this->allows($deleteOnly, $this->operationsFor($class, $method)), $class);
        }
    }

    /**
     * @param class-string<PublicApiController> $class
     * @return string[]
     */
    private function operationsFor(string $class, string $method): array
    {
        $reflection = new \ReflectionClass($class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(PublicApiController::class, 'request');
        $property->setValue($controller, Request::create('/api/v2/x', $method));

        /** @var string[] */
        return (new \ReflectionMethod($controller, 'requiredApiKeyOperations'))->invoke($controller);
    }

    /** @param string[] $operations */
    private function allows(ApiKeyScope $scope, array $operations): bool
    {
        foreach ($operations as $operation) {
            if (!$scope->isOperationTypeAllowed($operation)) {
                return false;
            }
        }

        return true;
    }
}
