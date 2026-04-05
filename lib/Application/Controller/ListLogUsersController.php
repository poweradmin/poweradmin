<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

/**
 * Script that displays list of event logs
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\DbUserLogger;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;

class ListLogUsersController extends BaseController
{

    private DbUserLogger $dbUserLogger;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->dbUserLogger = new DbUserLogger($this->db);
    }

    public function run(): void
    {
        $this->checkPermission('user_is_ueberuser', 'You do not have the permission to see any logs');

        // Set the current page for navigation highlighting
        $this->setCurrentPage('list_log_users');
        $this->setPageTitle(_('User Logs'));

        $this->showListLogUsers();
    }

    private function showListLogUsers(): void
    {
        $selected_page = 1;
        if (isset($_GET['start'])) {
            is_numeric($_GET['start']) ? $selected_page = $_GET['start'] : die(_('Invalid page number.'));
            if ($selected_page < 1) {
                die(_('Page number must be at least 1.'));
            }
        }

        $configManager = ConfigurationManager::getInstance();
        $logs_per_page = $configManager->get('interface', 'rows_per_page', 50);

        $filters = [];
        if (!empty($_GET['name'])) {
            $filters['name'] = $_GET['name'];
        }
        if (!empty($_GET['event_type'])) {
            $filters['event_type'] = $_GET['event_type'];
        }
        if (!empty($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])) {
            $filters['date_from'] = $_GET['date_from'];
        }
        if (!empty($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])) {
            $filters['date_to'] = $_GET['date_to'];
        }

        $number_of_logs = $this->dbUserLogger->countFilteredLogs($filters);
        $number_of_pages = ceil($number_of_logs / $logs_per_page);
        if ($number_of_logs != 0 && $selected_page > $number_of_pages) {
            die(_('Page number exceeds available pages.'));
        }
        $offset = ($selected_page - 1) * $logs_per_page;
        $logs = $this->dbUserLogger->getFilteredLogs($filters, $logs_per_page, $offset);

        $this->render('list_log_users.html', [
            'number_of_logs' => $number_of_logs,
            'name' => isset($_GET['name']) ? htmlspecialchars($_GET['name']) : null,
            'event_type' => isset($_GET['event_type']) ? htmlspecialchars($_GET['event_type']) : null,
            'date_from' => isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : null,
            'date_to' => isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : null,
            'event_types' => $this->dbUserLogger->getDistinctEventTypes(),
            'users' => $this->dbUserLogger->getDistinctUsers(),
            'data' => $logs,
            'selected_page' => $selected_page,
            'logs_per_page' => $logs_per_page,
            'pagination' => $this->createAndPresentPagination($number_of_logs, $logs_per_page, $filters),
            'iface_edit_show_id' => $configManager->get('interface', 'show_record_id', false),
        ]);
    }

    private function createAndPresentPagination(int $totalItems, string $itemsPerPage, array $filters = []): string
    {
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();

        $paginationService = new PaginationService();
        $pagination = $paginationService->createPagination($totalItems, $itemsPerPage, $currentPage);
        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');

        $queryParams = '';
        foreach ($filters as $key => $value) {
            $queryParams .= '&' . urlencode($key) . '=' . urlencode($value);
        }

        $presenter = new PaginationPresenter($pagination, $baseUrlPrefix . '/users/logs?start={PageNumber}' . $queryParams);

        return $presenter->present();
    }
}
