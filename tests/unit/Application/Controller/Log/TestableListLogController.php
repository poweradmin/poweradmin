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

namespace Poweradmin\Tests\Unit\Application\Controller\Log;

use Poweradmin\Application\Controller\Log\AbstractListLogController;

/**
 * Minimal concrete log listing controller exposing the shared filter and
 * pagination helpers of AbstractListLogController for direct testing.
 */
class TestableListLogController extends AbstractListLogController
{
    /** @var string[] Stand-in for the page-specific filter parameter list. */
    public array $additionalFilterParams = ['event_type'];

    /** @var callable|null Stand-in for a page-specific name transform (e.g. punycode). */
    public $nameTransform = null;

    protected function authorize(): bool
    {
        return true;
    }

    protected function getPageName(): string
    {
        return 'list_log_test';
    }

    protected function getPageTitleText(): string
    {
        return 'Test logs';
    }

    protected function getTemplateName(): string
    {
        return 'list_log_test.html';
    }

    protected function getPaginationRoute(): string
    {
        return '/test/logs?start={PageNumber}';
    }

    protected function getExportFilenamePrefix(): string
    {
        return 'test-logs';
    }

    protected function countLogs(array $filters): int
    {
        return 0;
    }

    protected function fetchLogs(array $filters, int $limit, int $offset): array
    {
        return [];
    }

    protected function getAdditionalFilterParams(): array
    {
        return $this->additionalFilterParams;
    }

    protected function transformNameFilter(string $name): string
    {
        return $this->nameTransform !== null ? ($this->nameTransform)($name) : parent::transformNameFilter($name);
    }

    public function buildFiltersForTest(): array
    {
        return $this->buildFilters();
    }

    public function clampToLastPageForTest(int $selectedPage, int $numberOfLogs, int $logsPerPage): int
    {
        return $this->clampToLastPage($selectedPage, $numberOfLogs, $logsPerPage);
    }

    public function parseLogEventsForTest(array $logs): array
    {
        return $this->parseLogEvents($logs);
    }
}
