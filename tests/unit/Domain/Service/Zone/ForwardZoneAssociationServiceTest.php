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

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ForwardZoneAssociationService;

class ForwardZoneAssociationServiceTest extends TestCase
{
    public function testGroupsForwardZonesPerReverseZoneAndCountsEachPtrOnce(): void
    {
        $repository = $this->createMock(ZoneRepositoryInterface::class);
        $repository->method('findForwardZonesByPtrRecords')->with([10, 11])->willReturn([
            ['reverse_domain_id' => 10, 'forward_domain_id' => 1, 'forward_domain_name' => 'a.example', 'ptr_content' => 'h1.a.example'],
            ['reverse_domain_id' => 10, 'forward_domain_id' => 1, 'forward_domain_name' => 'a.example', 'ptr_content' => 'h2.a.example'],
            // Same PTR matched a second forward zone: it must not be counted again.
            ['reverse_domain_id' => 10, 'forward_domain_id' => 2, 'forward_domain_name' => 'example', 'ptr_content' => 'h2.a.example'],
        ]);

        $service = new ForwardZoneAssociationService($repository);
        $result = $service->getAssociatedForwardZones([['id' => 10], ['id' => 11]]);

        $this->assertSame([
            10 => [['id' => 1, 'name' => 'a.example', 'ptr_records' => 2]],
            11 => [],
        ], $result);
    }

    public function testEmptyInputSkipsTheRepository(): void
    {
        $repository = $this->createMock(ZoneRepositoryInterface::class);
        $repository->expects($this->never())->method('findForwardZonesByPtrRecords');

        $this->assertSame([], (new ForwardZoneAssociationService($repository))->getAssociatedForwardZones([]));
    }
}
