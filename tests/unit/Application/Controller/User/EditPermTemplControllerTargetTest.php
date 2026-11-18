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

namespace Poweradmin\Tests\Unit\Application\Controller\User;

use Poweradmin\Application\Controller\User\EditPermTemplController;
use Poweradmin\Application\Service\User\PermissionTemplateWriteService;
use Poweradmin\Domain\Service\User\LastSuperAdminGuard;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The edit form posts its own `templ_id` alongside the route `{id}`, and the repository
 * keys every statement off the posted one. Without pinning it to the route,
 * POST /permissions/templates/5/edit with templ_id=1 rewrote template 1 (Administrator).
 */
class EditPermTemplControllerTargetTest extends SeamControllerTestCase
{
    public function testPostedTemplateIdCannotRedirectTheWrite(): void
    {
        $details = $this->captureWrite([
            'id' => '5',
            'templ_id' => '1',
            'templ_name' => 'Ordinary',
            'template_type' => 'user',
            'perm_id' => ['7'],
        ]);

        $this->assertSame(5, $details['templ_id'], 'The route id must win over the posted templ_id');
    }

    public function testAFormOpenedBeforeAnotherSaveIsRefusedAndRenderedFromStoredState(): void
    {
        $repository = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $repository->method('getPermissionTemplateDetails')->willReturn(['name' => 'Ops', 'descr' => '', 'template_type' => 'user']);
        // Another editor removed permission 44 after this form was opened
        $repository->method('getPermissionsByTemplateId')->willReturnCallback(
            fn(int $id = 0): array => $id === 0 ? [['id' => 43], ['id' => 44]] : [['id' => 43]]
        );
        $repository->expects($this->never())->method('updatePermissionTemplateDetails');
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('hasAdminPermission')->willReturn(true);
        $this->factory->method('permissionTemplateRepository')->willReturn($repository);
        $this->factory->method('permissionTemplateWriteService')
            ->willReturn(new PermissionTemplateWriteService($repository, $userRepository, new LastSuperAdminGuard($userRepository)));

        $staleState = EditPermTemplController::stateOf(['name' => 'Ops', 'descr' => '', 'template_type' => 'user'], [['id' => 43], ['id' => 44]]);
        $controller = $this->getMockBuilder(EditPermTemplController::class)
            ->setConstructorArgs([
                ['id' => '5', 'templ_name' => 'Ops', 'template_type' => 'user', 'perm_id' => ['43', '44'], 'form_state' => $staleState],
                true,
                $this->environment($this->configure(['security' => ['global_token_validation' => false]])),
            ])
            ->onlyMethods(['render'])
            ->getMock();
        $rendered = [];
        $controller->expects($this->once())->method('render')->willReturnCallback(function (string $template, array $params) use (&$rendered): void {
            $rendered = $params;
        });

        (new ReflectionMethod($controller, 'handleFormSubmission'))->invoke($controller);

        $this->assertSame([['id' => 43]], $rendered['perms_templ']);
        $this->assertSame(
            [['error', 'This permission template was changed by someone else after you opened it. Review the current values and save again.']],
            $this->messagesFor('edit_perm_templ')
        );
    }

    public function testRouteIdIsUsedWhenNoTemplateIdIsPosted(): void
    {
        $details = $this->captureWrite([
            'id' => '5',
            'templ_name' => 'Ordinary',
            'template_type' => 'user',
        ]);

        $this->assertSame(5, $details['templ_id']);
    }

    public function testScalarPermIdIsNormalisedSoTheWriterNeverIteratesAString(): void
    {
        $details = $this->captureWrite([
            'id' => '5',
            'templ_name' => 'Ordinary',
            'template_type' => 'user',
            'perm_id' => '7',
        ]);

        $this->assertSame([], $details['perm_id']);
    }

    public function testCheckedPermissionsReachTheWriterAsInts(): void
    {
        $details = $this->captureWrite([
            'id' => '5',
            'templ_name' => 'Ordinary',
            'template_type' => 'user',
            'perm_id' => ['7', '9'],
        ]);

        $this->assertSame([7, 9], $details['perm_id']);
    }

    /**
     * Invoke handleFormSubmission() and return the payload the repository was handed.
     * The repository throws so the method stops before redirect() ends the request.
     *
     * @param array<string, mixed> $requestData
     * @return array<string, mixed>
     */
    private function captureWrite(array $requestData): array
    {
        $captured = [];

        $repository = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('updatePermissionTemplateDetails')
            ->with($this->callback(function (array $details) use (&$captured): bool {
                $captured = $details;
                return true;
            }))
            ->willThrowException(new RuntimeException('stop before redirect'));

        // A superuser caller, so the content guard passes and the id plumbing is what
        // is actually under test here.
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('hasAdminPermission')->willReturn(true);

        $this->factory->method('permissionTemplateRepository')->willReturn($repository);
        $this->factory->method('permissionTemplateWriteService')
            ->willReturn(new PermissionTemplateWriteService($repository, $userRepository, new LastSuperAdminGuard($userRepository)));
        $config = $this->configure(['security' => ['global_token_validation' => false]]);
        // The stub repository reports an empty template, so the form opened from that state
        $requestData['form_state'] ??= EditPermTemplController::stateOf([], []);
        $controller = new EditPermTemplController($requestData, true, $this->environment($config));

        try {
            (new ReflectionMethod($controller, 'handleFormSubmission'))->invoke($controller);
            $this->fail('Expected the repository stub to short-circuit the redirect');
        } catch (RuntimeException $e) {
            $this->assertSame('stop before redirect', $e->getMessage());
        }

        return $captured;
    }
}
