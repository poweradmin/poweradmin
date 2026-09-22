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

namespace Poweradmin\Tests\Unit\Application\Controller\Record;

use Poweradmin\Application\Controller\Record\EditCommentController;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Controller\RequestHalted;
use ReflectionMethod;

/**
 * Builds the zone comment controller through the ControllerEnvironment seam.
 *
 * redirect() and showError() end the request in production, so each throws a
 * RequestHalted; the form render is recorded, and only rendered for real when
 * a test opts in to inspect its template variables.
 */
class TestableEditCommentController extends EditCommentController
{
    /** @var list<array{0: int, 1: bool}> */
    public array $formsShown = [];

    /** When true the form is rendered for real and its template variables land in $rendered */
    public bool $renderForms = false;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $rendered = [];

    public function __construct(array $request, ControllerEnvironment $environment)
    {
        (new ReflectionMethod(BaseController::class, '__construct'))->invoke($this, $request, true, $environment);
    }

    public function showCommentForm(int $zone_id, bool $perm_edit_comment): void
    {
        if ($this->renderForms) {
            parent::showCommentForm($zone_id, $perm_edit_comment);
            return;
        }
        $this->formsShown[] = [$zone_id, $perm_edit_comment];
    }

    public function render(string $template, array $params): void
    {
        $this->rendered[] = [$template, $params];
    }

    public function redirect(string $url, array $args = []): void
    {
        throw new RequestHalted(RequestHalted::KIND_REDIRECT, $url);
    }

    public function showError(string $error, ?string $recordName = null): void
    {
        throw new RequestHalted(RequestHalted::KIND_ERROR, $error);
    }

    protected function refreshPdnsCapabilities(): void
    {
    }
}
