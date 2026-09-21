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

namespace Poweradmin\Tests\Unit\Application\Controller\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\Zone\ChangeRequestController;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestService;
use RuntimeException;

#[CoversClass(ChangeRequestController::class)]
class ChangeRequestControllerTest extends ChangeRequestControllerTestCase
{
    private function makeController(bool $approvalEnabled, array $request = ['id' => '5']): TestableChangeRequestController
    {
        $config = $this->configure($approvalEnabled);

        return new TestableChangeRequestController($request, true, $this->environment($config));
    }

    private function storeRequest(?\Poweradmin\Domain\Model\ZoneChangeRequest $request): void
    {
        $repository = $this->createMock(ZoneChangeRequestRepositoryInterface::class);
        $repository->method('find')->willReturn($request);
        $this->factory->method('zoneChangeRequestRepository')->willReturn($repository);
    }

    public function testFeatureOffRendersTheNotFoundPage(): void
    {
        $controller = $this->makeController(false);

        $controller->run();

        $this->assertSame('404.html', $controller->rendered[0][0]);
        $this->assertNull($controller->redirectedTo);
    }

    public function testNonReviewerCannotApprove(): void
    {
        $this->storeRequest($this->pendingRequest(requesterId: self::USER_ID));
        $this->reviewer(false);
        $service = $this->createMock(ZoneChangeRequestService::class);
        $service->expects($this->never())->method('approve');
        $this->factory->method('zoneChangeRequestService')->willReturn($service);
        $this->post(['action' => 'approve']);
        $controller = $this->makeController(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to review this change request.');
        $controller->run();
    }

    public function testStrangerCannotOpenTheRequest(): void
    {
        $this->storeRequest($this->pendingRequest(requesterId: 99));
        $this->reviewer(false);
        $controller = $this->makeController(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to view this change request.');
        $controller->run();
    }

    public function testReviewerApproveCallsTheServiceAndRedirectsBack(): void
    {
        $this->storeRequest($this->pendingRequest(requesterId: 3));
        $this->reviewer(true);
        $service = $this->createMock(ZoneChangeRequestService::class);
        $service->expects($this->once())
            ->method('approve')
            ->with(5, self::USER_ID, 'reviewer', 'fine')
            ->willReturn(ZoneChangeRequestResult::ok(5, 'Change request approved and applied.'));
        $this->factory->method('zoneChangeRequestService')->willReturn($service);
        $this->messages->expects($this->once())->method('addMessage')->with('change_request', 'success', $this->anything());
        $this->post(['action' => 'approve', 'review_comment' => ' fine ']);
        $controller = $this->makeController(true);

        $controller->run();

        $this->assertSame('/zones/requests/5', $controller->redirectedTo);
    }

    public function testRequesterCancelRedirectsToTheList(): void
    {
        $this->storeRequest($this->pendingRequest(requesterId: self::USER_ID));
        $this->reviewer(false);
        $service = $this->createMock(ZoneChangeRequestService::class);
        $service->expects($this->once())->method('cancel')->with(5, self::USER_ID)->willReturn(ZoneChangeRequestResult::ok(5, 'Change request cancelled.'));
        $this->factory->method('zoneChangeRequestService')->willReturn($service);
        $this->post(['action' => 'cancel']);
        $controller = $this->makeController(true);

        $controller->run();

        $this->assertSame('/zones/requests', $controller->redirectedTo);
    }

    public function testRequesterViewRendersActionsWithoutReviewButtons(): void
    {
        $this->storeRequest($this->pendingRequest(requesterId: self::USER_ID));
        $this->reviewer(false);
        $service = $this->createMock(ZoneChangeRequestService::class);
        $service->method('staleActions')->willReturn([0]);
        $service->method('baseSerialMismatch')->willReturn(true);
        $this->factory->method('zoneChangeRequestService')->willReturn($service);
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('zoneIdExists')->willReturn(true);
        $this->factory->method('domainRepository')->willReturn($domains);
        $controller = $this->makeController(true);

        $controller->run();

        [$template, $params] = $controller->rendered[0];
        $this->assertSame('change_request.html', $template);
        $this->assertFalse($params['can_review']);
        $this->assertTrue($params['can_cancel']);
        $this->assertSame(1, $params['stale_count']);
        $this->assertTrue($params['actions'][0]['stale']);
        $this->assertSame(['ttl'], $params['actions'][0]['changed']);
        $this->assertTrue($params['base_serial_mismatch']);
    }
}
