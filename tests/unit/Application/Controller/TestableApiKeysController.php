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

use Poweradmin\Application\Controller\ApiKeysController;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Test double that skips BaseController's constructor, which opens a database
 * connection, and records rendered templates instead of drawing them.
 */
class TestableApiKeysController extends ApiKeysController
{
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    public array $rendered = [];

    /** @var array<string, bool> */
    public array $permissions = [];

    public UserContextService $userContext;

    public function __construct()
    {
    }

    public function setConfig(ConfigurationManager $config): void
    {
        $this->config = $config;
    }

    /** @param array<string, mixed> $requestData */
    public function setRequestData(array $requestData): void
    {
        $this->requestData = $requestData;
    }

    public function render(string $template, array $params): void
    {
        $this->rendered[] = [$template, $params];
    }

    protected function hasPermission(string $permission): bool
    {
        return $this->permissions[$permission] ?? false;
    }

    protected function getUserContextService(): UserContextService
    {
        return $this->userContext;
    }
}
