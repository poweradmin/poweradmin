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

use Poweradmin\Application\Controller\Zone\ListReverseZonesController;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Service\Zone\ForwardZoneAssociationService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Zone\ZoneSortingService;
use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;
use Poweradmin\Tests\Unit\Application\Controller\ControllerHalt;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Builds the reverse zone list controller through the ControllerEnvironment
 * seam. Its own constructor takes no environment, so BaseController's is
 * invoked directly and the private collaborators are wired exactly as the real
 * constructor wires them, off the seam's service factory.
 *
 * The exiting methods are captured instead: showError() renders an error page
 * and exits, so it throws a ControllerHalt to stop the run at the same statement.
 */
class TestableListReverseZonesController extends ListReverseZonesController
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $rendered = [];
    public ?string $redirectedTo = null;

    public function __construct(array $request, ControllerEnvironment $environment)
    {
        (new ReflectionMethod(BaseController::class, '__construct'))->invoke($this, $request, true, $environment);

        $userContext = new UserContextService();
        $this->plant('dnsDataService', $this->services()->dnsDataService());
        $this->plant('forwardZoneAssociationService', new ForwardZoneAssociationService($this->services()->zoneRepository()));
        $this->plant('userContextService', $userContext);
        $this->plant('zoneSortingService', new ZoneSortingService(new ReverseZoneSorting(), $userContext));
    }

    private function plant(string $property, object $value): void
    {
        (new ReflectionProperty(ListReverseZonesController::class, $property))->setValue($this, $value);
    }

    public function render(string $template, array $params): void
    {
        $this->rendered[] = [$template, $params];
    }

    /** @return array<string, mixed> */
    public function renderedParams(): array
    {
        return $this->rendered[0][1] ?? [];
    }

    public function redirect(string $url, array $args = []): void
    {
        $this->redirectedTo = $url;
        throw new ControllerHalt(ControllerHalt::KIND_REDIRECT, $url);
    }

    public function checkCondition(bool $condition, string $errorMessage): void
    {
        if ($condition) {
            throw new ControllerHalt(ControllerHalt::KIND_CONDITION, $errorMessage);
        }
    }

    public function showError(string $error, ?string $recordName = null): void
    {
        throw new ControllerHalt(ControllerHalt::KIND_ERROR, $error);
    }
}
