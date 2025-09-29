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
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;

class ListLogZonesController extends BaseController
{
    private DbZoneLogger $dbZoneLogger;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->dbZoneLogger = new DbZoneLogger($this->db);
    }

    public function run(): void
    {
        $this->checkPermission('user_is_ueberuser', 'You do not have the permission to see any logs');

        // Set the current page for navigation highlighting
        $this->requestData['page'] = 'list_log_zones';

        $this->showListLogZones();
    }

    private function showListLogZones(): void
    {
        $selected_page = 1;
        if (isset($_GET['start'])) {
            is_numeric($_GET['start']) ? $selected_page = $_GET['start'] : die("Unknown page.");
            if ($selected_page < 0) {
                die('Unknown page.');
            }
        }

        $configManager = ConfigurationManager::getInstance();
        $logs_per_page = $configManager->get('interface', 'rows_per_page', 50);

        if (isset($_GET['name']) && $_GET['name'] != '') {
            $number_of_logs = $this->dbZoneLogger->countLogsByDomain(DnsIdnService::toPunycode($_GET['name']));
            $number_of_pages = ceil($number_of_logs / $logs_per_page);
            if ($number_of_logs != 0 && $selected_page > $number_of_pages) {
                die('Unknown page');
            }
            $logs = $this->dbZoneLogger->getLogsForDomain(DnsIdnService::toPunycode($_GET['name']), $logs_per_page, ($selected_page - 1) * $logs_per_page);
        } else {
            $number_of_logs = $this->dbZoneLogger->countAllLogs();
            $number_of_pages = ceil($number_of_logs / $logs_per_page);
            if ($number_of_logs != 0 && $selected_page > $number_of_pages) {
                die('Unknown page');
            }
            $logs = $this->dbZoneLogger->getAllLogs($logs_per_page, ($selected_page - 1) * $logs_per_page);
        }

        $this->render('list_log_zones.html', [
            'number_of_logs' => $number_of_logs,
            'name' => isset($_GET['name']) ? htmlspecialchars($_GET['name']) : null,
            'data' => $logs,
            'selected_page' => $selected_page,
            'logs_per_page' => $logs_per_page,
            'pagination' => $this->createAndPresentPagination($number_of_logs, $logs_per_page),
            'iface_edit_show_id' => $configManager->get('interface', 'show_record_id', false),
        ]);
    }

    private function createAndPresentPagination(int $totalItems, string $itemsPerPage): string
    {
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();

        $paginationService = new PaginationService();
        $pagination = $paginationService->createPagination($totalItems, $itemsPerPage, $currentPage);
        $presenter = new PaginationPresenter($pagination, '/zones/logs?start={PageNumber}');

        return $presenter->present();
    }
}
