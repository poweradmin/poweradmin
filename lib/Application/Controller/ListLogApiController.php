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

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\DbApiLogger;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;

class ListLogApiController extends BaseController
{
    private DbApiLogger $dbApiLogger;
    private Request $httpRequest;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->dbApiLogger = new DbApiLogger($this->db);
        $this->httpRequest = new Request();
    }

    public function run(): void
    {
        $this->checkPermission('user_is_ueberuser', 'You do not have the permission to see any logs');

        $this->setCurrentPage('list_log_api');
        $this->setPageTitle(_('API Logs'));

        $this->showListLogApi();
    }

    private function buildFilters(): array
    {
        $filters = [];
        $name = $this->httpRequest->getQueryParam('name');
        if (!empty($name)) {
            $filters['name'] = $name;
        }
        $eventType = $this->httpRequest->getQueryParam('event_type');
        if (!empty($eventType)) {
            $filters['event_type'] = $eventType;
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

    private function showListLogApi(): void
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

        $number_of_logs = $this->dbApiLogger->countFilteredLogs($filters);
        $number_of_pages = ceil($number_of_logs / $logs_per_page);
        if ($number_of_logs != 0 && $selected_page > $number_of_pages) {
            die(_('Page number exceeds available pages.'));
        }
        $offset = ($selected_page - 1) * $logs_per_page;
        $logs = $this->dbApiLogger->getFilteredLogs($filters, $logs_per_page, $offset);

        $this->render('list_log_api.html', [
            'number_of_logs' => $number_of_logs,
            'name' => htmlspecialchars($this->httpRequest->getQueryParam('name', '')),
            'event_type' => htmlspecialchars($this->httpRequest->getQueryParam('event_type', '')),
            'date_from' => htmlspecialchars($this->httpRequest->getQueryParam('date_from', '')),
            'date_to' => htmlspecialchars($this->httpRequest->getQueryParam('date_to', '')),
            'event_types' => $this->dbApiLogger->getDistinctEventTypes(),
            'users' => $this->dbApiLogger->getDistinctUsers(),
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

        $presenter = new PaginationPresenter($pagination, $baseUrlPrefix . '/settings/api/logs?start={PageNumber}' . $queryParams);

        return $presenter->present();
    }

    private function exportLogs(array $filters, string $format): void
    {
        $logs = $this->dbApiLogger->getFilteredLogs($filters, 100000, 0);
        $parsed = [];
        foreach ($logs as $log) {
            $row = ['timestamp' => $log['created_at']];
            if (str_contains($log['event'], 'operation:')) {
                $parts = explode(' ', $log['event']);
                foreach ($parts as $part) {
                    $kv = explode(':', $part, 2);
                    if (count($kv) === 2) {
                        $row[$kv[0]] = $kv[1];
                    }
                }
            } else {
                $row['event'] = $log['event'];
            }
            $parsed[] = $row;
        }

        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="api-logs-' . date('Y-m-d') . '.json"');
            echo json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="api-logs-' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'w');
            if (!empty($parsed)) {
                $allKeys = [];
                foreach ($parsed as $row) {
                    $allKeys = array_merge($allKeys, array_keys($row));
                }
                $allKeys = array_unique($allKeys);
                fputcsv($output, $allKeys);
                foreach ($parsed as $row) {
                    $csvRow = [];
                    foreach ($allKeys as $key) {
                        $csvRow[] = $row[$key] ?? '';
                    }
                    fputcsv($output, $csvRow);
                }
            }
            fclose($output);
        }
        exit;
    }
}
