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

namespace Poweradmin\Application\Controller\Log;

use Poweradmin\Domain\Model\Permission;
use Poweradmin\Infrastructure\Logger\DbUserLogger;

/**
 * Renders the user log page with filters and CSV/JSON export.
 */
class ListLogUsersController extends AbstractListLogController
{
    private DbUserLogger $dbUserLogger;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->dbUserLogger = $this->services()->userLogger();
    }

    protected function authorize(): bool
    {
        if (
            !$this->hasPermission(Permission::PERM_USER_IS_UEBERUSER)
            && !$this->hasPermission(Permission::PERM_USER_LOGS_VIEW)
        ) {
            $this->checkPermission(Permission::PERM_USER_IS_UEBERUSER, 'You do not have the permission to see any logs');
            return false;
        }
        return true;
    }

    protected function getPageName(): string
    {
        return 'list_log_users';
    }

    protected function getPageTitleText(): string
    {
        return _('User logs');
    }

    protected function getTemplateName(): string
    {
        return 'list_log_users.html';
    }

    protected function getPaginationRoute(): string
    {
        return '/users/logs?start={PageNumber}';
    }

    protected function getExportFilenamePrefix(): string
    {
        return 'user-logs';
    }

    protected function countLogs(array $filters): int
    {
        return $this->dbUserLogger->countFilteredLogs($filters);
    }

    protected function fetchLogs(array $filters, int $limit, int $offset): array
    {
        return $this->dbUserLogger->getFilteredLogs($filters, $limit, $offset);
    }

    protected function getAdditionalRenderParams(): array
    {
        return [
            'event_type' => $this->httpRequest->getQueryParam('event_type', ''),
            'event_types' => $this->dbUserLogger->getDistinctEventTypes(),
            'users' => $this->dbUserLogger->getDistinctUsers(),
        ];
    }
}
