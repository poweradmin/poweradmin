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

namespace Poweradmin\Application\Module;

use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Mail\EmailTemplateService;
use Poweradmin\Application\Service\Record\RecordAddService;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Zone\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;

/**
 * The module-facing service surface: the collaborators a module controller may
 * obtain from the core through BaseController::moduleServices().
 *
 * Modules under lib/Module depend on the core only through this interface, the
 * ModuleInterface contract and the SDK value types RecordAddResult, RecordAddAccess,
 * ChangeRequestMessages, ZoneCreateRequest and ZoneCreateFormMessages. A method is
 * added here when a module needs it; the rest of ControllerServiceFactory is core-only.
 */
interface ModuleServices
{
    public function auditService(): AuditService;

    public function dnsBackendProvider(): DnsBackendProviderInterface;

    public function domainManager(): DomainManagerInterface;

    public function domainRepository(): DomainRepositoryInterface;

    public function emailTemplateService(): EmailTemplateService;

    public function permissionService(): PermissionService;

    public function recordAddService(): RecordAddService;

    public function recordManager(): RecordManagerInterface;

    public function recordManagerService(): RecordManagerService;

    public function recordRepository(): RecordRepositoryInterface;

    public function userGroupRepository(): UserGroupRepositoryInterface;

    public function userRepository(): UserRepositoryInterface;

    public function zoneCreateOwnershipResolver(): ZoneCreateOwnershipResolver;

    public function zoneOwnershipModeService(): ZoneOwnershipModeService;
}
