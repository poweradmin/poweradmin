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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\ZoneOwnershipInputFactory;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipInput;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipResolution;
use Poweradmin\Domain\Service\Validation\Refusal;

#[CoversClass(ZoneOwnershipInputFactory::class)]
class ZoneOwnershipInputFactoryTest extends TestCase
{
    #[Test]
    public function omittedFieldsStayOmitted(): void
    {
        $input = ZoneOwnershipInputFactory::fromJsonBody(['name' => 'example.com']);

        $this->assertEquals(new ZoneOwnershipInput(false, null, null), $input);
    }

    #[Test]
    public function explicitNullOwnerIsSupplied(): void
    {
        $input = ZoneOwnershipInputFactory::fromJsonBody(['owner_user_id' => null, 'group_ids' => [11]]);

        $this->assertEquals(ZoneOwnershipInput::owner(null, [11]), $input);
    }

    #[Test]
    public function coercesNumericStringsAndKeepsDuplicatesForTheResolver(): void
    {
        $input = ZoneOwnershipInputFactory::fromJsonBody(['owner_user_id' => '42', 'group_ids' => [3, '3', 5]]);

        $this->assertEquals(ZoneOwnershipInput::owner(42, [3, 3, 5]), $input);
    }

    #[Test]
    public function emptyGroupListIsAnEmptyList(): void
    {
        $input = ZoneOwnershipInputFactory::fromJsonBody(['group_ids' => []]);

        $this->assertEquals(ZoneOwnershipInput::ownerOmitted([]), $input);
    }

    public static function invalidBodies(): array
    {
        return [
            'group_ids not an array' => [['group_ids' => 'nope'], 'group_ids must be an array of integers'],
            'group_ids with a word' => [['group_ids' => [1, 'two']], 'group_ids must be an array of integers'],
            'group_ids with a float' => [['group_ids' => [3.5]], 'group_ids must be an array of integers'],
            'owner not numeric' => [['owner_user_id' => 'abc'], 'owner_user_id must be a numeric ID'],
            'owner negative string' => [['owner_user_id' => '-1'], 'owner_user_id must be a numeric ID'],
            'owner boolean' => [['owner_user_id' => true], 'owner_user_id must be a numeric ID'],
        ];
    }

    #[Test]
    #[DataProvider('invalidBodies')]
    public function refusesValuesOfTheWrongShape(array $body, string $message): void
    {
        $result = ZoneOwnershipInputFactory::fromJsonBody($body);

        $this->assertInstanceOf(ZoneOwnershipResolution::class, $result);
        $this->assertSame($message, $result->error);
        $this->assertSame(Refusal::INVALID_INPUT, $result->refusal);
        $this->assertSame(ZoneOwnershipResolution::INVALID_INPUT, $result->code);
    }
}
