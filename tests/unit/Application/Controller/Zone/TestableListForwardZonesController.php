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

use Poweradmin\Application\Controller\Zone\ListForwardZonesController;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\ZoneSortingService;
use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;
use Poweradmin\Application\Controller\RequestHalted;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Builds the forward zone list controller through the ControllerEnvironment
 * seam. Its own constructor takes no environment, so BaseController's is
 * invoked directly and the one private collaborator is planted afterwards.
 *
 * The exiting methods are captured instead: checkCondition() and showError()
 * render an error page and exit, redirect() sends a header and exits, so each
 * throws a RequestHalted to stop the run at the same statement.
 */
class TestableListForwardZonesController extends ListForwardZonesController
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $rendered = [];
    public ?string $redirectedTo = null;

    public function __construct(array $request, ControllerEnvironment $environment)
    {
        (new ReflectionMethod(BaseController::class, '__construct'))->invoke($this, $request, true, $environment);
        (new ReflectionProperty(ListForwardZonesController::class, 'zoneSortingService'))
            ->setValue($this, new ZoneSortingService(new ReverseZoneSorting(), $this->getUserContextService()));
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
        throw new RequestHalted(RequestHalted::KIND_REDIRECT, $url);
    }

    public function checkCondition(bool $condition, string $errorMessage): void
    {
        if ($condition) {
            throw new RequestHalted(RequestHalted::KIND_CONDITION, $errorMessage);
        }
    }

    public function showError(string $error, ?string $recordName = null): void
    {
        throw new RequestHalted(RequestHalted::KIND_ERROR, $error);
    }
}
