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

namespace Poweradmin\Tests\Unit\Application\Controller;

use Poweradmin\Application\Controller\EditRecordController;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Domain\Service\UserContextService;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Builds the edit-record controller through the ControllerEnvironment seam.
 *
 * Its constructor reaches past the service factory into the repository factory
 * to build the two comment services, so those are handed in ready-made; the
 * rest is wired exactly as the real constructor wires it.
 */
class TestableEditRecordController extends EditRecordController
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $rendered = [];
    public ?string $redirectedTo = null;

    public function __construct(
        array $request,
        ControllerEnvironment $environment,
        RecordCommentService $recordCommentService,
        RecordCommentSyncService $commentSyncService
    ) {
        (new ReflectionMethod(BaseController::class, '__construct'))->invoke($this, $request, true, $environment);

        $this->plant('recordCommentService', $recordCommentService);
        $this->plant('commentSyncService', $commentSyncService);
        $this->plant('recordTypeService', new RecordTypeService($this->getConfig()));
        $this->plant('userContextService', new UserContextService());
        $this->plant('permissionService', $this->services()->permissionService());
    }

    private function plant(string $property, object $value): void
    {
        (new ReflectionProperty(EditRecordController::class, $property))->setValue($this, $value);
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

    public function showError(string $error, ?string $recordName = null): void
    {
        throw new ControllerHalt(ControllerHalt::KIND_ERROR, $error);
    }

    protected function refreshPdnsCapabilities(): void
    {
    }
}
