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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\ChangeApprovalContext;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Auth\PermissionService;

/**
 * The change-approval questions the pages ask, answered without a session: with
 * the feature off nothing may reach a repository, and the review scope narrows
 * to the intersection of the approve and edit levels.
 */
class ChangeApprovalContextTest extends TestCase
{
    private const ZONE_ID = 7;
    private const USER_ID = 3;

    private function config(bool $enabled, bool $requireReviewForAll = false): ConfigurationInterface
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            static fn(string $group, string $key, mixed $default = null): mixed => match ([$group, $key]) {
                ['approval', 'enabled'] => $enabled,
                ['approval', 'require_review_for_all'] => $requireReviewForAll,
                default => $default,
            }
        );

        return $config;
    }

    private function context(
        ConfigurationInterface $config,
        ?PermissionService $permissions = null,
        ?ZoneOwnershipRepositoryInterface $zones = null,
        ?ZoneChangeRequestRepositoryInterface $requests = null
    ): ChangeApprovalContext {
        return new ChangeApprovalContext(
            $config,
            fn(): PermissionService => $permissions ?? $this->createMock(PermissionService::class),
            fn(): ZoneOwnershipRepositoryInterface => $zones ?? $this->createMock(ZoneOwnershipRepositoryInterface::class),
            fn(): ZoneChangeRequestRepositoryInterface => $requests ?? $this->createMock(ZoneChangeRequestRepositoryInterface::class)
        );
    }

    /** A repository that fails the test if anything touches it. */
    private function untouchedRequests(): ZoneChangeRequestRepositoryInterface
    {
        $requests = $this->createMock(ZoneChangeRequestRepositoryInterface::class);
        $requests->expects($this->never())->method($this->anything());

        return $requests;
    }

    public function testEnabledFollowsTheConfigurationFlag(): void
    {
        $this->assertTrue($this->context($this->config(true))->enabled());
        $this->assertFalse($this->context($this->config(false))->enabled());
    }

    public function testAnAnonymousCallerMayDoNothing(): void
    {
        $context = $this->context($this->config(true), requests: $this->untouchedRequests());

        $this->assertSame(ChangeApprovalPolicy::MODE_NONE, $context->modeForZone(null, self::ZONE_ID));
        $this->assertFalse($context->canReviewZone(null, self::ZONE_ID));
        $this->assertSame([], $context->reviewScope(null));
        $this->assertSame([], $context->pendingByZone(null, [self::ZONE_ID]));
        $this->assertSame(0, $context->pendingReviewCount(null));
    }

    public function testWithApprovalOffAnEditorWritesDirectlyAndNoRequestsAreRead(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevelForZone')->willReturn('all');
        $permissions->method('userOwnsZone')->willReturn(true);
        // With the feature off the request level must not even be consulted
        $permissions->expects($this->never())->method('getChangeRequestPermissionLevelForZone');

        $context = $this->context($this->config(false), $permissions, requests: $this->untouchedRequests());

        $this->assertSame(ChangeApprovalPolicy::MODE_DIRECT, $context->modeForZone(self::USER_ID, self::ZONE_ID));
        $this->assertFalse($context->canReviewZone(self::USER_ID, self::ZONE_ID));
        $this->assertSame([], $context->reviewScope(self::USER_ID));
        $this->assertSame([], $context->pendingByZone(self::USER_ID, [self::ZONE_ID]));
        $this->assertSame(0, $context->pendingReviewCount(self::USER_ID));
    }

    public function testWithApprovalOnARequesterFilesInsteadOfWriting(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevelForZone')->willReturn('none');
        $permissions->method('getChangeRequestPermissionLevelForZone')->willReturn('own');
        $permissions->method('userOwnsZone')->willReturn(true);

        $context = $this->context($this->config(true), $permissions);

        $this->assertSame(ChangeApprovalPolicy::MODE_REQUEST, $context->modeForZone(self::USER_ID, self::ZONE_ID));
    }

    public function testReviewScopeIsEveryZoneOnlyWhenBothLevelsAreAll(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getChangeApprovePermissionLevel')->willReturn('all');
        $permissions->method('getEditPermissionLevel')->willReturn('all');

        $this->assertNull($this->context($this->config(true), $permissions)->reviewScope(self::USER_ID));
    }

    public function testReviewScopeIsEmptyWhenEitherLevelIsNone(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getChangeApprovePermissionLevel')->willReturn('all');
        $permissions->method('getEditPermissionLevel')->willReturn('none');

        $this->assertSame([], $this->context($this->config(true), $permissions)->reviewScope(self::USER_ID));
    }

    public function testAPartialLevelNarrowsTheScopeToTheOwnedZones(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getChangeApprovePermissionLevel')->willReturn('own');
        $permissions->method('getEditPermissionLevel')->willReturn('all');
        $zones = $this->createMock(ZoneOwnershipRepositoryInterface::class);
        $zones->method('getOwnedZoneIds')->with(self::USER_ID)->willReturn([4, 9]);

        $this->assertSame([4, 9], $this->context($this->config(true), $permissions, $zones)->reviewScope(self::USER_ID));
    }

    public function testAnEmptyZoneListIsAnsweredWithoutReadingRequests(): void
    {
        $context = $this->context($this->config(true), requests: $this->untouchedRequests());

        $this->assertSame([], $context->pendingByZone(self::USER_ID, []));
    }

    public function testAUserWhoNeitherFilesNorReviewsSeesNoPendingCounts(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getChangeRequestPermissionLevel')->willReturn('none');
        $permissions->method('getChangeApprovePermissionLevel')->willReturn('none');

        $context = $this->context($this->config(true), $permissions, requests: $this->untouchedRequests());

        $this->assertSame([], $context->pendingByZone(self::USER_ID, [self::ZONE_ID]));
    }

    public function testRequireReviewForAllMakesEvenABystanderSeePendingCounts(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getChangeRequestPermissionLevel')->willReturn('none');
        $permissions->method('getChangeApprovePermissionLevel')->willReturn('none');
        $permissions->method('getEditPermissionLevel')->willReturn('none');
        $requests = $this->createMock(ZoneChangeRequestRepositoryInterface::class);
        $requests->expects($this->once())->method('countPendingByZone')
            ->with([self::ZONE_ID], [], self::USER_ID)
            ->willReturn([self::ZONE_ID => 2]);

        $context = $this->context($this->config(true, true), $permissions, requests: $requests);

        $this->assertSame([self::ZONE_ID => 2], $context->pendingByZone(self::USER_ID, [self::ZONE_ID]));
    }

    public function testTheBadgeCountSkipsTheQueryForAnEmptyScope(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getChangeApprovePermissionLevel')->willReturn('none');
        $permissions->method('getEditPermissionLevel')->willReturn('none');

        $context = $this->context($this->config(true), $permissions, requests: $this->untouchedRequests());

        $this->assertSame(0, $context->pendingReviewCount(self::USER_ID));
    }

    public function testTheBadgeCountAsksTheRepositoryForAReviewableScope(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getChangeApprovePermissionLevel')->willReturn('all');
        $permissions->method('getEditPermissionLevel')->willReturn('all');
        $requests = $this->createMock(ZoneChangeRequestRepositoryInterface::class);
        $requests->expects($this->once())->method('countPending')->with(null)->willReturn(5);

        $this->assertSame(5, $this->context($this->config(true), $permissions, requests: $requests)->pendingReviewCount(self::USER_ID));
    }
}
