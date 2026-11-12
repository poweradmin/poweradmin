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
 */

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ForwardZoneAssociationService;

class ForwardZoneAssociationServiceKeepOwnedTest extends TestCase
{
    public function testKeepsOnlyOwnedForwardZones(): void
    {
        $service = new ForwardZoneAssociationService($this->createMock(ZoneRepositoryInterface::class));

        $map = [
            10 => [
                ['id' => 1, 'name' => 'mine.example', 'ptr_records' => 2],
                ['id' => 2, 'name' => 'theirs.example', 'ptr_records' => 1],
            ],
            11 => [['id' => 2, 'name' => 'theirs.example', 'ptr_records' => 1]],
            12 => [],
        ];

        $result = $service->keepOwned($map, fn(int $id): bool => $id === 1);

        $this->assertSame([['id' => 1, 'name' => 'mine.example', 'ptr_records' => 2]], $result[10]);
        $this->assertSame([], $result[11]);
        $this->assertSame([], $result[12]);
    }
}
