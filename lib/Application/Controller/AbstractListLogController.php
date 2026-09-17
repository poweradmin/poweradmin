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

use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Utility\CsvFormulaEscaper;

/**
 * Shared flow for the log listing pages (API, user, group and zone logs):
 * filter parsing, pagination with last-page clamping, and CSV/JSON export.
 *
 * Subclasses supply the permission gate, the data source, the template and
 * route names, and any page-specific filters or render parameters. The
 * permission models intentionally differ per page and stay in the subclasses.
 */
abstract class AbstractListLogController extends BaseController
{
    public function run(): void
    {
        if (!$this->authorize()) {
            return;
        }

        // Set the current page for navigation highlighting
        $this->setCurrentPage($this->getPageName());
        $this->setPageTitle($this->getPageTitleText());

        $this->showLogs();
    }

    /**
     * Permission gate for the page. Returns true when the listing may render;
     * implementations either halt the request themselves (checkPermission()
     * exits on failure) or return false after showing an error.
     */
    abstract protected function authorize(): bool;

    /** Page identifier for navigation highlighting (e.g. 'list_log_users'). */
    abstract protected function getPageName(): string;

    /** Translated page title. */
    abstract protected function getPageTitleText(): string;

    /** Template file rendered for the listing. */
    abstract protected function getTemplateName(): string;

    /** Pagination route with the `{PageNumber}` placeholder in place. */
    abstract protected function getPaginationRoute(): string;

    /** Export filename prefix (e.g. 'user-logs' → user-logs-YYYY-MM-DD.csv). */
    abstract protected function getExportFilenamePrefix(): string;

    /** Number of log entries matching the filters. */
    abstract protected function countLogs(array $filters): int;

    /** One page of log entries matching the filters. */
    abstract protected function fetchLogs(array $filters, int $limit, int $offset): array;

    /**
     * Query parameters (beyond name and the date range) copied verbatim into
     * the filters when present.
     *
     * @return string[]
     */
    protected function getAdditionalFilterParams(): array
    {
        return ['event_type'];
    }

    /** Transforms the name filter value before use (identity by default). */
    protected function transformNameFilter(string $name): string
    {
        return $name;
    }

    /**
     * Hook between filter parsing and the export/listing branches; may adjust
     * the filters and prime subclass state (e.g. ownership scoping).
     */
    protected function prepareListing(array &$filters): void
    {
    }

    /** Page-specific render parameters merged over the shared ones. */
    protected function getAdditionalRenderParams(): array
    {
        return [];
    }

    protected function buildFilters(): array
    {
        $filters = [];
        $name = $this->httpRequest->getQueryParam('name');
        if (!empty($name)) {
            $filters['name'] = $this->transformNameFilter($name);
        }
        foreach ($this->getAdditionalFilterParams() as $param) {
            $value = $this->httpRequest->getQueryParam($param);
            if (!empty($value)) {
                $filters[$param] = $value;
            }
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

    /**
     * Clamp to the last page rather than dying when the request is out of range.
     */
    protected function clampToLastPage(int $selectedPage, int $numberOfLogs, int $logsPerPage): int
    {
        $numberOfPages = (int)ceil($numberOfLogs / $logsPerPage);
        if ($numberOfPages > 0 && $selectedPage > $numberOfPages) {
            return $numberOfPages;
        }
        return $selectedPage;
    }

    private function showLogs(): void
    {
        $selected_page = 1;
        $start = $this->httpRequest->getQueryParam('start');
        if ($start !== null && is_numeric($start)) {
            $selected_page = max(1, (int)$start);
        }

        $logs_per_page = $this->resolveRowsPerPage(50);

        $filters = $this->buildFilters();
        $this->prepareListing($filters);

        // Handle export
        $exportFormat = $this->httpRequest->getQueryParam('export');
        if (!empty($exportFormat) && in_array($exportFormat, ['csv', 'json'])) {
            $this->exportLogs($filters, $exportFormat);
            return;
        }

        $number_of_logs = $this->countLogs($filters);
        $selected_page = $this->clampToLastPage($selected_page, $number_of_logs, $logs_per_page);
        $offset = ($selected_page - 1) * $logs_per_page;
        $logs = $this->fetchLogs($filters, $logs_per_page, $offset);

        $this->render($this->getTemplateName(), array_merge([
            'number_of_logs' => $number_of_logs,
            'name' => $this->httpRequest->getQueryParam('name', ''),
            'date_from' => $this->httpRequest->getQueryParam('date_from', ''),
            'date_to' => $this->httpRequest->getQueryParam('date_to', ''),
            'data' => $logs,
            'selected_page' => $selected_page,
            'logs_per_page' => $logs_per_page,
            'pagination' => $this->presentPagination($number_of_logs, $logs_per_page, $this->getPaginationRoute(), $filters),
            'iface_edit_show_id' => $this->config->get('interface', 'show_record_id', false),
        ], $this->getAdditionalRenderParams()));
    }

    protected function exportLogs(array $filters, string $format): void
    {
        $logs = $this->fetchLogs($filters, 100000, 0);
        $parsed = $this->parseLogEvents($logs);

        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $this->getExportFilenamePrefix() . '-' . date('Y-m-d') . '.json"');
            echo json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $this->getExportFilenamePrefix() . '-' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'w');
            if (!empty($parsed)) {
                $this->writeCsvRows($output, $parsed);
            }
            fclose($output);
        }
        exit;
    }

    /**
     * Flattens log rows for export: key:value pairs from structured events,
     * the raw event text otherwise.
     */
    protected function parseLogEvents(array $logs): array
    {
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
        return $parsed;
    }

    /**
     * Writes the CSV header and body for a non-empty export: the header is the
     * union of all row keys, and rows are padded so columns stay aligned.
     *
     * @param resource $output
     */
    protected function writeCsvRows($output, array $parsed): void
    {
        $allKeys = [];
        foreach ($parsed as $row) {
            $allKeys = array_merge($allKeys, array_keys($row));
        }
        $allKeys = array_unique($allKeys);
        fputcsv($output, CsvFormulaEscaper::escapeRow($allKeys));
        foreach ($parsed as $row) {
            $csvRow = [];
            foreach ($allKeys as $key) {
                $csvRow[] = $row[$key] ?? '';
            }
            fputcsv($output, CsvFormulaEscaper::escapeRow($csvRow));
        }
    }
}
