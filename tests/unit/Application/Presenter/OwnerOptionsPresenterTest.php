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

namespace Poweradmin\Tests\Unit\Application\Presenter;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\OwnerOptionsPresenter;

/**
 * The zone-owner picker: who it lists, and what it shows again after a refused
 * submit.
 */
class OwnerOptionsPresenterTest extends TestCase
{
    private const USERS = [
        ['id' => 1, 'fullname' => 'Admin'],
        ['id' => 2, 'fullname' => 'Operator'],
        ['id' => 3, 'fullname' => 'Client'],
    ];

    public function testACallerWhoMaySeeOthersIsOfferedEveryone(): void
    {
        $this->assertSame(self::USERS, OwnerOptionsPresenter::offered(true, self::USERS, 2));
    }

    public function testACallerRestrictedToThemselvesIsOfferedOnlyTheirOwnRow(): void
    {
        $offered = OwnerOptionsPresenter::offered(false, self::USERS, 2);

        $this->assertCount(1, $offered);
        $this->assertSame(2, $offered[0]['id']);
    }

    public function testTheOfferedListIsAlwaysAGapFreeArray(): void
    {
        // The current user is the last row, so filtering leaves key 2 behind
        $offered = OwnerOptionsPresenter::offered(false, self::USERS, 3);

        $this->assertSame([0], array_keys($offered));
    }

    public function testStringIdsStillMatchTheCurrentUser(): void
    {
        $offered = OwnerOptionsPresenter::offered(false, [['id' => '2']], 2);

        $this->assertCount(1, $offered);
    }

    public function testAnUnknownUserIsOfferedNobody(): void
    {
        $this->assertSame([], OwnerOptionsPresenter::offered(false, self::USERS, 99));
        $this->assertSame([], OwnerOptionsPresenter::offered(false, self::USERS, null));
    }

    public function testAnExplicitNoOwnerChoiceIsPreserved(): void
    {
        $this->assertSame('', OwnerOptionsPresenter::preservedChoice(self::USERS, '', 2));
    }

    public function testAPostedOwnerThePickerOffersIsPreserved(): void
    {
        $this->assertSame(3, OwnerOptionsPresenter::preservedChoice(self::USERS, '3', 2));
        $this->assertSame(3, OwnerOptionsPresenter::preservedChoice(self::USERS, 3, 2));
    }

    public function testAPostedOwnerThePickerDoesNotOfferFallsBackToTheCurrentUser(): void
    {
        $this->assertSame(2, OwnerOptionsPresenter::preservedChoice(self::USERS, '99', 2));
    }

    public function testMalformedInputFallsBackToTheCurrentUser(): void
    {
        $this->assertSame(2, OwnerOptionsPresenter::preservedChoice(self::USERS, 'abc', 2));
        $this->assertSame(2, OwnerOptionsPresenter::preservedChoice(self::USERS, ['3'], 2));
        $this->assertSame(2, OwnerOptionsPresenter::preservedChoice(self::USERS, null, 2));
    }

    public function testAnAnonymousCallerFallsBackToZero(): void
    {
        $this->assertSame(0, OwnerOptionsPresenter::preservedChoice(self::USERS, 'abc', null));
    }
}
