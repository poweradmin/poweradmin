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

use DateTimeImmutable;
use DateInterval;
use DateTimeZone;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;

class ListRecordChangesController extends BaseController
{
    private const TIME_WINDOWS = [
        'P1M' => 'One month ago',
        'P1W' => 'One week ago',
        'P1D' => 'One day ago',
        'PT6H' => '6 hours ago',
        'PT1H' => '1 hour ago',
    ];

    private RecordChangeLogger $changeLogger;
    private Request $httpRequest;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->changeLogger = new RecordChangeLogger($this->db);
        $this->httpRequest = new Request();
    }

    public function run(): void
    {
        $this->checkPermission('user_is_ueberuser', 'You do not have the permission to see record change logs.');

        $this->setCurrentPage('list_record_changes');
        $this->setPageTitle(_('Record Change Log'));

        $this->showRecordChanges();
    }

    private function buildFilters(): array
    {
        $filters = [];

        $action = $this->httpRequest->getQueryParam('action');
        if (!empty($action)) {
            $filters['action'] = $action;
        }

        $user = $this->httpRequest->getQueryParam('user');
        if (!empty($user)) {
            $filters['user'] = $user;
        }

        $zoneId = $this->httpRequest->getQueryParam('zone_id');
        if (!empty($zoneId) && is_numeric($zoneId)) {
            $filters['zone_id'] = (int) $zoneId;
        }

        $window = $this->httpRequest->getQueryParam('window');
        if (is_string($window) && isset(self::TIME_WINDOWS[$window])) {
            try {
                $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->sub(new DateInterval($window));
                $filters['date_from'] = $cutoff->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                // ignore malformed window
            }
        } else {
            $dateFrom = $this->httpRequest->getQueryParam('date_from');
            if (is_string($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
                $filters['date_from'] = $dateFrom . ' 00:00:00';
            }
        }

        $dateTo = $this->httpRequest->getQueryParam('date_to');
        if (is_string($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $filters['date_to'] = $dateTo . ' 23:59:59';
        }

        return $filters;
    }

    private function showRecordChanges(): void
    {
        $selectedPage = 1;
        $start = $this->httpRequest->getQueryParam('start');
        if ($start !== null) {
            if (!is_numeric($start)) {
                die(_('Invalid page number.'));
            }
            $selectedPage = (int) $start;
            if ($selectedPage < 1) {
                die(_('Page number must be at least 1.'));
            }
        }

        $configManager = ConfigurationManager::getInstance();
        $logsPerPage = (int) $configManager->get('interface', 'rows_per_page', 50);

        $filters = $this->buildFilters();

        $exportFormat = $this->httpRequest->getQueryParam('export');
        if (!empty($exportFormat) && in_array($exportFormat, ['csv', 'json'], true)) {
            $this->exportLogs($filters, $exportFormat);
            return;
        }

        $totalLogs = $this->changeLogger->countFiltered($filters);
        $pages = (int) ceil($totalLogs / max(1, $logsPerPage));
        if ($totalLogs > 0 && $selectedPage > $pages) {
            die(_('Page number exceeds available pages.'));
        }
        $offset = ($selectedPage - 1) * $logsPerPage;
        $logs = $this->changeLogger->getFiltered($filters, $logsPerPage, $offset);

        $timeWindows = [];
        foreach (self::TIME_WINDOWS as $key => $label) {
            $timeWindows[] = ['key' => $key, 'label' => _($label)];
        }

        $this->render('list_record_changes.html', [
            'number_of_logs' => $totalLogs,
            'data' => $logs,
            'actions' => $this->changeLogger->getDistinctActions(),
            'users' => $this->changeLogger->getDistinctUsers(),
            'time_windows' => $timeWindows,
            'action_filter' => htmlspecialchars((string) $this->httpRequest->getQueryParam('action', '')),
            'user_filter' => htmlspecialchars((string) $this->httpRequest->getQueryParam('user', '')),
            'zone_id_filter' => htmlspecialchars((string) $this->httpRequest->getQueryParam('zone_id', '')),
            'window_filter' => htmlspecialchars((string) $this->httpRequest->getQueryParam('window', '')),
            'date_from' => htmlspecialchars((string) $this->httpRequest->getQueryParam('date_from', '')),
            'date_to' => htmlspecialchars((string) $this->httpRequest->getQueryParam('date_to', '')),
            'selected_page' => $selectedPage,
            'logs_per_page' => $logsPerPage,
            'pagination' => $this->createAndPresentPagination($totalLogs, $logsPerPage, $filters),
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
            $queryParams .= '&' . urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        $presenter = new PaginationPresenter($pagination, $baseUrlPrefix . '/zones/changes?start={PageNumber}' . $queryParams);

        return $presenter->present();
    }

    private function exportLogs(array $filters, string $format): void
    {
        $logs = $this->changeLogger->getFiltered($filters, 100000, 0);
        $rows = array_map(static function (array $log): array {
            return [
                'timestamp' => $log['created_at'],
                'action' => $log['action'],
                'username' => $log['username'],
                'user_id' => $log['user_id'],
                'zone_id' => $log['zone_id'],
                'record_id' => $log['record_id'],
                'client_ip' => $log['client_ip'] ?? null,
                'before_state' => $log['before_state'],
                'after_state' => $log['after_state'],
            ];
        }, $logs);

        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="record-changes-' . date('Y-m-d') . '.json"');
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="record-changes-' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'w');
            if (!empty($rows)) {
                fputcsv($output, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($output, $row);
                }
            }
            fclose($output);
        }
        exit;
    }
}
