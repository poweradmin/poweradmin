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

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Domain\Repository\ExternalIdentityRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Model\UserGroupMember;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\ValueObject\OidcUserInfo;
use Poweradmin\Infrastructure\Logger\Logger;
use TestHelpers\FakeConfiguration;

/**
 * A provider-assigned template that no mapping matches any more must be revoked;
 * when that cannot be done the sign-in is refused instead of keeping the template.
 */
class UserProvisioningServiceRevocationRefusalTest extends TestCase
{
    private const USER_ID = 5;

    private UserRepositoryInterface&MockObject $users;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('countUberusersAfter')->willReturn(1);
    }

    private UserGroupMemberRepositoryInterface&MockObject $members;

    /** @var list<array{0: string, 1: string}> */
    private array $logged = [];

    private ?string $failOnLogLine = null;

    /**
     * Signs in a user holding template 1 from the provider, matching no mapping.
     * Template 1 is the one the mapping grants ('Editors') unless $mapped is false.
     */
    private function sync(string $templateSource, string $defaultTemplate, bool $mapped = true, bool $withGroupCleanup = false, array $extraMapping = []): bool
    {
        $this->users->method('getProvisioningProfile')
            ->willReturn(['perm_templ' => 1, 'perm_templ_source' => $templateSource, 'auth_method' => 'oidc']);

        $this->members = $this->members ?? $this->createMock(UserGroupMemberRepositoryInterface::class);
        $this->members->method('findByUserId')->willReturn($withGroupCleanup ? [UserGroupMember::create(3, self::USER_ID)] : []);

        $groups = $this->createMock(UserGroupLookupInterface::class);
        $groups->method('findIdByExactName')->willReturn(3);

        $logger = $this->createMock(Logger::class);
        $logger->method('log')->willReturnCallback(function (string $level, string $message): void {
            if ($this->failOnLogLine !== null && str_starts_with($message, $this->failOnLogLine)) {
                throw new \RuntimeException('log sink down');
            }
            $this->logged[] = [$level, $message];
        });

        $service = new UserProvisioningService(
            new FakeConfiguration(['oidc' => [
                'default_permission_template' => $defaultTemplate,
                'permission_template_mapping' => $extraMapping + ['idp-editors' => $mapped ? 'Editors' : 'Other'],
                'group_mapping' => ['idp-editors' => 'Editors'],
            ]]),
            $logger,
            $this->users,
            $this->createMock(ExternalIdentityRepositoryInterface::class),
            $groups,
            $this->members
        );

        return $service->syncExistingUser(self::USER_ID, new OidcUserInfo('admin', '', groups: []));
    }

    /** @param array<string, int|null> $ids */
    private function templateIds(array $ids): void
    {
        $this->users->method('findPermissionTemplateIdByName')->willReturnCallback(static fn(string $name): ?int => $ids[$name] ?? null);
    }

    public function testRefusesAMappedTemplateWhenTheDefaultTemplateCannotBeResolved(): void
    {
        $this->templateIds(['Editors' => 1]);
        $this->users->expects($this->never())->method('updateProvisionedUser');

        $this->assertFalse($this->sync('oidc', 'Guest'));
    }

    public function testSkipsANonScalarMappingValueAndStillFindsTheMappedTemplate(): void
    {
        $this->templateIds(['Editors' => 1]);
        $this->users->expects($this->never())->method('updateProvisionedUser');

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        }, E_WARNING);
        try {
            $this->assertFalse($this->sync('oidc', 'Guest', extraMapping: ['misconfigured' => ['Editors']]));
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $warnings);
    }

    public function testKeepsADefaultDerivedTemplateWithAWarningWhenTheDefaultCannotBeResolved(): void
    {
        $this->templateIds(['Other' => 9]);
        $this->users->expects($this->never())->method('updateProvisionedUser');

        $this->assertTrue($this->sync('oidc', 'Guest', mapped: false));
        $this->assertContains('warning', array_column($this->logged, 0));
    }

    public function testRefusesWhenTheRevokingWriteFails(): void
    {
        $this->templateIds(['Editors' => 1, 'Guest' => 7]);
        $this->users->method('templateGrantsUberuser')->willReturn(false);
        $this->users->method('updateProvisionedUser')->willThrowException(new \RuntimeException('db down'));

        $this->assertFalse($this->sync('oidc', 'Guest'));
    }

    public function testRevokesToTheDefaultTemplateAndAllowsTheSignIn(): void
    {
        $this->templateIds(['Editors' => 1, 'Guest' => 7]);
        $this->users->method('templateGrantsUberuser')->willReturn(false);
        $this->users->expects($this->once())->method('updateProvisionedUser')
            ->with(self::USER_ID, $this->callback(static fn(array $f): bool => $f['perm_templ'] === 7));

        $this->assertTrue($this->sync('oidc', 'Guest'));
    }

    public function testAGroupCleanupFailureAfterTheRevocationDoesNotRefuse(): void
    {
        $this->templateIds(['Editors' => 1, 'Guest' => 7]);
        $this->users->method('templateGrantsUberuser')->willReturn(false);
        $this->users->expects($this->once())->method('updateProvisionedUser');
        $this->members = $this->createMock(UserGroupMemberRepositoryInterface::class);
        $this->members->method('remove')->willReturn(true);
        // The removal's own failures are caught inside; this makes the step after it throw
        $this->failOnLogLine = 'Removed user';

        $this->assertTrue($this->sync('oidc', 'Guest', withGroupCleanup: true));
    }

    public function testAnAdminAssignedTemplateIsLeftAloneWithoutADefault(): void
    {
        $this->templateIds(['Editors' => 1]);
        $this->users->expects($this->never())->method('updateProvisionedUser');

        $this->assertTrue($this->sync('manual', ''));
    }
}
