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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\ZoneAccessPolicy;

#[CoversClass(ChangeApprovalPolicy::class)]
class ChangeApprovalPolicyTest extends TestCase
{
    private const EDIT_LEVELS = ['all', 'own', 'own_as_client', 'none'];
    private const REQUEST_LEVELS = ['all', 'own', 'none'];

    /**
     * The full matrix, with the expectation derived from the two building blocks
     * so a change in either ZoneAccessPolicy rule surfaces here.
     */
    public static function modeMatrix(): iterable
    {
        foreach ([false, true] as $enabled) {
            foreach ([false, true] as $force) {
                foreach (self::EDIT_LEVELS as $edit) {
                    foreach (self::REQUEST_LEVELS as $request) {
                        foreach ([false, true] as $owner) {
                            $canEdit = ZoneAccessPolicy::canEditZone($edit, $owner);
                            $canRequest = ZoneAccessPolicy::levelAppliesToZone($request, $owner);

                            if (!$enabled) {
                                $expected = $canEdit ? 'direct' : 'none';
                            } elseif ($force) {
                                $expected = ($canEdit || $canRequest) ? 'request' : 'none';
                            } elseif ($canEdit) {
                                $expected = 'direct';
                            } else {
                                $expected = $canRequest ? 'request' : 'none';
                            }

                            $name = sprintf(
                                'enabled=%d force=%d edit=%s request=%s owner=%d',
                                $enabled,
                                $force,
                                $edit,
                                $request,
                                $owner
                            );
                            yield $name => [$enabled, $force, $edit, $request, $owner, $expected];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('modeMatrix')]
    public function testMode(bool $enabled, bool $force, string $edit, string $request, bool $owner, string $expected): void
    {
        $this->assertSame($expected, ChangeApprovalPolicy::mode($enabled, $force, $edit, $request, $owner));
    }

    public function testFeatureOffMatchesTodaysEditRule(): void
    {
        $this->assertSame(ChangeApprovalPolicy::MODE_DIRECT, ChangeApprovalPolicy::mode(false, false, 'own', 'none', true));
        $this->assertSame(ChangeApprovalPolicy::MODE_NONE, ChangeApprovalPolicy::mode(false, false, 'own', 'all', false));
        // A request grant alone never unlocks anything while the feature is off.
        $this->assertSame(ChangeApprovalPolicy::MODE_NONE, ChangeApprovalPolicy::mode(false, true, 'none', 'all', true));
    }

    public function testRequestOnlyUserFilesRequestsWhenEnabled(): void
    {
        $this->assertSame(ChangeApprovalPolicy::MODE_REQUEST, ChangeApprovalPolicy::mode(true, false, 'none', 'own', true));
        $this->assertSame(ChangeApprovalPolicy::MODE_NONE, ChangeApprovalPolicy::mode(true, false, 'none', 'own', false));
        $this->assertSame(ChangeApprovalPolicy::MODE_REQUEST, ChangeApprovalPolicy::mode(true, false, 'none', 'all', false));
    }

    public function testEditorsStayDirectUnlessReviewIsForcedForAll(): void
    {
        $this->assertSame(ChangeApprovalPolicy::MODE_DIRECT, ChangeApprovalPolicy::mode(true, false, 'all', 'none', false));
        $this->assertSame(ChangeApprovalPolicy::MODE_REQUEST, ChangeApprovalPolicy::mode(true, true, 'all', 'none', false));
        $this->assertSame(ChangeApprovalPolicy::MODE_REQUEST, ChangeApprovalPolicy::mode(true, true, 'own_as_client', 'none', true));
        $this->assertSame(ChangeApprovalPolicy::MODE_NONE, ChangeApprovalPolicy::mode(true, true, 'none', 'none', true));
    }

    public static function canReviewProvider(): array
    {
        return [
            'approve all + edit all' => ['all', 'all', false, true],
            'approve all + edit own on owned zone' => ['all', 'own', true, true],
            'approve all + edit own on foreign zone' => ['all', 'own', false, false],
            'approve all + own_as_client on owned zone' => ['all', 'own_as_client', true, true],
            'approve all without edit' => ['all', 'none', true, false],
            'approve own + edit all on owned zone' => ['own', 'all', true, true],
            'approve own + edit all on foreign zone' => ['own', 'all', false, false],
            'approve own + edit own on owned zone' => ['own', 'own', true, true],
            'approve own + edit own on foreign zone' => ['own', 'own', false, false],
            'approve none + edit all' => ['none', 'all', true, false],
            'nothing' => ['none', 'none', false, false],
        ];
    }

    #[DataProvider('canReviewProvider')]
    public function testCanReview(string $approve, string $edit, bool $owner, bool $expected): void
    {
        $this->assertSame($expected, ChangeApprovalPolicy::canReview($approve, $edit, $owner));
    }
}
