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

/**
 * Script that displays list of event logs
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
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
    private Request $httpRequest;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->dbZoneLogger = new DbZoneLogger($this->db, $this->createDnsBackendProvider());
        $this->httpRequest = new Request();
    }

    public function run(): void
    {
        $this->checkPermission('user_is_ueberuser', 'You do not have the permission to see any logs');

        // Set the current page for navigation highlighting
        $this->setCurrentPage('list_log_zones');
        $this->setPageTitle(_('Zone Logs'));

        $this->showListLogZones();
    }

    private function buildFilters(): array
    {
        $filters = [];
        $name = $this->httpRequest->getQueryParam('name');
        if (!empty($name)) {
            $filters['name'] = DnsIdnService::toPunycode($name);
        }
        $operation = $this->httpRequest->getQueryParam('operation');
        if (!empty($operation)) {
            $filters['operation'] = $operation;
        }
        $user = $this->httpRequest->getQueryParam('user');
        if (!empty($user)) {
            $filters['user'] = $user;
        }
        $dateFrom = $this->httpRequest->getQueryParam('date_from');
        if (!empty($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $filters['date_from'] = $dateFrom;
        }
        $dateTo = $this->httpRequest->getQueryParam('date_to');
        if (!empty($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $filters['date_to'] = $dateTo;
        }
        return $filters;
    }

    private function showListLogZones(): void
    {
        $selected_page = 1;
        $start = $this->httpRequest->getQueryParam('start');
        if ($start !== null) {
            is_numeric($start) ? $selected_page = (int)$start : die(_('Invalid page number.'));
            if ($selected_page < 1) {
                die(_('Page number must be at least 1.'));
            }
        }

        $configManager = ConfigurationManager::getInstance();
        $logs_per_page = $configManager->get('interface', 'rows_per_page', 50);

        $filters = $this->buildFilters();

        // Handle export
        $exportFormat = $this->httpRequest->getQueryParam('export');
        if (!empty($exportFormat) && in_array($exportFormat, ['csv', 'json'])) {
            $this->exportLogs($filters, $exportFormat);
            return;
        }

        $number_of_logs = $this->dbZoneLogger->countFilteredLogs($filters);
        $number_of_pages = ceil($number_of_logs / $logs_per_page);
        if ($number_of_logs != 0 && $selected_page > $number_of_pages) {
            die(_('Page number exceeds available pages.'));
        }
        $offset = ($selected_page - 1) * $logs_per_page;
        $logs = $this->dbZoneLogger->getFilteredLogs($filters, $logs_per_page, $offset);

        $this->render('list_log_zones.html', [
            'number_of_logs' => $number_of_logs,
            'name' => htmlspecialchars($this->httpRequest->getQueryParam('name', '')),
            'operation' => htmlspecialchars($this->httpRequest->getQueryParam('operation', '')),
            'user_filter' => htmlspecialchars($this->httpRequest->getQueryParam('user', '')),
            'date_from' => htmlspecialchars($this->httpRequest->getQueryParam('date_from', '')),
            'date_to' => htmlspecialchars($this->httpRequest->getQueryParam('date_to', '')),
            'operations' => $this->dbZoneLogger->getDistinctOperations(),
            'users' => $this->dbZoneLogger->getDistinctUsers(),
            'data' => $logs,
            'selected_page' => $selected_page,
            'logs_per_page' => $logs_per_page,
            'pagination' => $this->createAndPresentPagination($number_of_logs, $logs_per_page, $filters),
            'iface_edit_show_id' => $configManager->get('interface', 'show_record_id', false),
        ]);
    }

    private function createAndPresentPagination(int $totalItems, int $itemsPerPage, array $filters = []): string
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

        $presenter = new PaginationPresenter($pagination, $baseUrlPrefix . '/zones/logs?start={PageNumber}' . $queryParams);

        return $presenter->present();
    }

    private function exportLogs(array $filters, string $format): void
    {
        $logs = $this->dbZoneLogger->getFilteredLogs($filters, 100000, 0);
        $parsed = $this->parseLogEvents($logs);

        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="zone-logs-' . date('Y-m-d') . '.json"');
            echo json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="zone-logs-' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'w');
            if (!empty($parsed)) {
                fputcsv($output, array_keys($parsed[0]));
                foreach ($parsed as $row) {
                    fputcsv($output, $row);
                }
            }
            fclose($output);
        }
        exit;
    }

    private function parseLogEvents(array $logs): array
    {
        $result = [];
        foreach ($logs as $log) {
            $row = [
                'timestamp' => $log['created_at'],
            ];
            $parts = explode(' ', $log['event']);
            foreach ($parts as $part) {
                $kv = explode(':', $part, 2);
                if (count($kv) === 2) {
                    $row[$kv[0]] = $kv[1];
                }
            }
            $result[] = $row;
        }
        return $result;
    }
}
