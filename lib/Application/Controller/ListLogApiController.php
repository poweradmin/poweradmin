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

namespace Poweradmin\Application\Controller;

use Poweradmin\Domain\Model\Permission;
use Poweradmin\Infrastructure\Logger\DbApiLogger;

/**
 * Renders the API request log page for admins with filters and CSV/JSON export.
 */
class ListLogApiController extends AbstractListLogController
{
    private DbApiLogger $dbApiLogger;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->dbApiLogger = $this->services()->apiLogger();
    }

    protected function authorize(): bool
    {
        $this->checkPermission(Permission::PERM_USER_IS_UEBERUSER, 'You do not have the permission to see any logs');
        return true;
    }

    protected function getPageName(): string
    {
        return 'list_log_api';
    }

    protected function getPageTitleText(): string
    {
        return _('API Logs');
    }

    protected function getTemplateName(): string
    {
        return 'list_log_api.html';
    }

    protected function getPaginationRoute(): string
    {
        return '/settings/api/logs?start={PageNumber}';
    }

    protected function getExportFilenamePrefix(): string
    {
        return 'api-logs';
    }

    protected function countLogs(array $filters): int
    {
        return $this->dbApiLogger->countFilteredLogs($filters);
    }

    protected function fetchLogs(array $filters, int $limit, int $offset): array
    {
        return $this->dbApiLogger->getFilteredLogs($filters, $limit, $offset);
    }

    protected function getAdditionalRenderParams(): array
    {
        return [
            'event_type' => $this->httpRequest->getQueryParam('event_type', ''),
            'event_types' => $this->dbApiLogger->getDistinctEventTypes(),
            'users' => $this->dbApiLogger->getDistinctUsers(),
        ];
    }
}
