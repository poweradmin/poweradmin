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

use PDO;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use RuntimeException;

/**
 * For anonymous test subclasses of a controller: skips BaseController's constructor
 * (it opens a database connection), turns the error pages, which normally exit, into
 * an exception the test can read, and hands the repository factories back as doubles.
 */
trait ControllerTestStubs
{
    /** @var string[] */
    public array $permissions = [];
    public ?RecordRepositoryInterface $records = null;
    public ?RecordManagerInterface $recordManager = null;
    public ?DomainRepositoryInterface $domains = null;
    public ?ZoneRepositoryInterface $zones = null;
    public ?PermissionService $permissionServiceStub = null;
    public bool $ownsZones = false;

    public function __construct(array $request)
    {
        $this->requestData = $request;
        $this->db = new PDO('sqlite::memory:');
    }

    public function setConfig(ConfigurationManager $config): void
    {
        $this->config = $config;
    }

    public function showError(string $error, ?string $recordName = null): void
    {
        throw new RuntimeException($error);
    }

    public function checkCondition(bool $condition, string $errorMessage): void
    {
        if ($condition) {
            throw new RuntimeException($errorMessage);
        }
    }

    public function validateCsrfToken(): void
    {
    }

    protected function isZoneOwner(int $zoneId): bool
    {
        return $this->ownsZones;
    }

    protected function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    protected function createRecordRepository(): RecordRepositoryInterface
    {
        return $this->records ?? throw new RuntimeException('No record repository stub');
    }

    protected function createRecordManager(): RecordManagerInterface
    {
        return $this->recordManager ?? throw new RuntimeException('No record manager stub');
    }

    protected function createDomainRepository(): DomainRepositoryInterface
    {
        return $this->domains ?? throw new RuntimeException('No domain repository stub');
    }

    protected function createZoneRepository(): ZoneRepositoryInterface
    {
        return $this->zones ?? throw new RuntimeException('No zone repository stub');
    }

    protected function createPermissionService(): PermissionService
    {
        return $this->permissionServiceStub ?? throw new RuntimeException('No permission service stub');
    }
}
