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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ApiKeyScope;

class ApiKeyScopeTest extends TestCase
{
    public function testUnrestrictedAllowsEverything(): void
    {
        $scope = ApiKeyScope::unrestricted();

        $this->assertFalse($scope->hasZoneRestriction());
        $this->assertFalse($scope->isReadonly());
        $this->assertNull($scope->getZoneIds());
        $this->assertNull($scope->getOperations());

        $this->assertTrue($scope->isZoneAllowed(1));
        $this->assertTrue($scope->isZoneAllowed(999));
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertTrue($scope->isOperationAllowed($method), "$method should be allowed");
        }
    }

    public function testMethodToOperationMapping(): void
    {
        $this->assertSame(ApiKeyScope::OP_VIEW, ApiKeyScope::methodToOperation('GET'));
        $this->assertSame(ApiKeyScope::OP_VIEW, ApiKeyScope::methodToOperation('get'));
        $this->assertSame(ApiKeyScope::OP_VIEW, ApiKeyScope::methodToOperation('HEAD'));
        $this->assertSame(ApiKeyScope::OP_CREATE, ApiKeyScope::methodToOperation('POST'));
        $this->assertSame(ApiKeyScope::OP_UPDATE, ApiKeyScope::methodToOperation('PUT'));
        $this->assertSame(ApiKeyScope::OP_UPDATE, ApiKeyScope::methodToOperation('PATCH'));
        $this->assertSame(ApiKeyScope::OP_DELETE, ApiKeyScope::methodToOperation('DELETE'));
    }

    public function testReadonlyBlocksEverythingButView(): void
    {
        $scope = new ApiKeyScope(null, null, true);

        $this->assertTrue($scope->isReadonly());
        $this->assertTrue($scope->isOperationAllowed('GET'));
        $this->assertFalse($scope->isOperationAllowed('POST'));
        $this->assertFalse($scope->isOperationAllowed('PUT'));
        $this->assertFalse($scope->isOperationAllowed('PATCH'));
        $this->assertFalse($scope->isOperationAllowed('DELETE'));
    }

    public function testOperationSubsetIsEnforced(): void
    {
        $scope = new ApiKeyScope(null, [ApiKeyScope::OP_VIEW, ApiKeyScope::OP_CREATE], false);

        $this->assertTrue($scope->isOperationAllowed('GET'));
        $this->assertTrue($scope->isOperationAllowed('POST'));
        $this->assertFalse($scope->isOperationAllowed('PUT'));
        $this->assertFalse($scope->isOperationAllowed('DELETE'));
    }

    public function testReadonlyOverridesOperationSubset(): void
    {
        // Even if create is in the operation list, read-only wins.
        $scope = new ApiKeyScope(null, [ApiKeyScope::OP_VIEW, ApiKeyScope::OP_CREATE], true);

        $this->assertTrue($scope->isOperationAllowed('GET'));
        $this->assertFalse($scope->isOperationAllowed('POST'));
    }

    public function testIsOperationTypeAllowedForMixedActionEndpoints(): void
    {
        // create-only key: only the create action passes, regardless of HTTP method.
        $scope = new ApiKeyScope(null, [ApiKeyScope::OP_CREATE], false);
        $this->assertTrue($scope->isOperationTypeAllowed(ApiKeyScope::OP_CREATE));
        $this->assertFalse($scope->isOperationTypeAllowed(ApiKeyScope::OP_UPDATE));
        $this->assertFalse($scope->isOperationTypeAllowed(ApiKeyScope::OP_DELETE));
        $this->assertFalse($scope->isOperationTypeAllowed(ApiKeyScope::OP_VIEW));

        // update-only key: update passes even though it would map from a POST body action.
        $updateOnly = new ApiKeyScope(null, [ApiKeyScope::OP_UPDATE], false);
        $this->assertTrue($updateOnly->isOperationTypeAllowed(ApiKeyScope::OP_UPDATE));
        $this->assertFalse($updateOnly->isOperationTypeAllowed(ApiKeyScope::OP_CREATE));

        // read-only blocks every write operation type.
        $readonly = new ApiKeyScope(null, null, true);
        $this->assertTrue($readonly->isOperationTypeAllowed(ApiKeyScope::OP_VIEW));
        $this->assertFalse($readonly->isOperationTypeAllowed(ApiKeyScope::OP_CREATE));
        $this->assertFalse($readonly->isOperationTypeAllowed(ApiKeyScope::OP_UPDATE));
        $this->assertFalse($readonly->isOperationTypeAllowed(ApiKeyScope::OP_DELETE));
    }

    public function testZoneRestrictionMembership(): void
    {
        $scope = new ApiKeyScope([10, 20, 30], null, false);

        $this->assertTrue($scope->hasZoneRestriction());
        $this->assertSame([10, 20, 30], $scope->getZoneIds());
        $this->assertTrue($scope->isZoneAllowed(10));
        $this->assertTrue($scope->isZoneAllowed(30));
        $this->assertFalse($scope->isZoneAllowed(40));
        $this->assertFalse($scope->isZoneAllowed(0));
    }

    public function testEmptyZoneListBlocksAllZones(): void
    {
        // An empty (but non-null) list is a real restriction: no zone matches.
        $scope = new ApiKeyScope([], null, false);

        $this->assertTrue($scope->hasZoneRestriction());
        $this->assertFalse($scope->isZoneAllowed(1));
    }
}
