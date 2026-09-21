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
use Poweradmin\Infrastructure\Logger\DbGroupLogger;

/**
 * Renders the group log page with filters and CSV/JSON export.
 */
class ListLogGroupsController extends AbstractListLogController
{
    private DbGroupLogger $dbGroupLogger;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->dbGroupLogger = $this->services()->groupLogger();
    }

    protected function authorize(): bool
    {
        if (!$this->config->get('permissions', 'show_group_access_templates', true)) {
            $this->showError(_('Group management is not enabled.'));
            return false;
        }

        if (
            !$this->hasPermission(Permission::PERM_USER_IS_UEBERUSER)
            && !$this->hasPermission(Permission::PERM_GROUP_LOGS_VIEW)
        ) {
            $this->checkPermission(Permission::PERM_USER_IS_UEBERUSER, 'You do not have the permission to see any logs');
            return false;
        }
        return true;
    }

    protected function getPageName(): string
    {
        return 'list_log_groups';
    }

    protected function getPageTitleText(): string
    {
        return _('Group logs');
    }

    protected function getTemplateName(): string
    {
        return 'list_log_groups.html';
    }

    protected function getPaginationRoute(): string
    {
        return '/groups/logs?start={PageNumber}';
    }

    protected function getExportFilenamePrefix(): string
    {
        return 'group-logs';
    }

    protected function countLogs(array $filters): int
    {
        return $this->dbGroupLogger->countFilteredLogs($filters);
    }

    protected function fetchLogs(array $filters, int $limit, int $offset): array
    {
        return $this->dbGroupLogger->getFilteredLogs($filters, $limit, $offset);
    }

    protected function getAdditionalRenderParams(): array
    {
        return [
            'event_type' => $this->httpRequest->getQueryParam('event_type', ''),
            'event_types' => $this->dbGroupLogger->getDistinctEventTypes(),
        ];
    }
}
