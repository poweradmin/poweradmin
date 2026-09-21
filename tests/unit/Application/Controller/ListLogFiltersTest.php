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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\AbstractListLogController;
use Poweradmin\Application\Http\Request as HttpRequest;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\NullLogger;

/**
 * The log listing pages (API, user, group and zone logs) share the filter
 * parsing and pagination clamp in AbstractListLogController: date filters must
 * match YYYY-MM-DD exactly, blank filters are dropped, and an out-of-range page
 * lands on the last page instead of dying.
 */
#[CoversClass(AbstractListLogController::class)]
class ListLogFiltersTest extends TestCase
{
    private ?string $previousRequestMethod = null;

    private array $previousGet = [];

    protected function setUp(): void
    {
        $this->previousRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->previousGet = $_GET;
    }

    protected function tearDown(): void
    {
        if ($this->previousRequestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->previousRequestMethod;
        }
        $_GET = $this->previousGet;
    }

    private function makeController(array $queryParams = []): TestableListLogController
    {
        $_GET = $queryParams;

        $environment = new ControllerEnvironment(
            ConfigurationManager::getInstance(),
            $this->createMock(PDO::class),
            new NullLogger(),
            null,
            new HttpRequest(),
            null,
            null,
            $this->createMock(UserContextService::class)
        );

        return new TestableListLogController([], true, $environment);
    }

    public function testAllFiltersPresent(): void
    {
        $filters = $this->makeController([
            'name' => 'alice',
            'event_type' => 'user_login',
            'date_from' => '2026-01-01',
            'date_to' => '2026-02-28',
        ])->buildFiltersForTest();

        $this->assertSame([
            'name' => 'alice',
            'event_type' => 'user_login',
            'date_from' => '2026-01-01',
            'date_to' => '2026-02-28',
        ], $filters);
    }

    public function testAbsentAndBlankParametersAreOmitted(): void
    {
        $controller = $this->makeController([
            'name' => '',
            'event_type' => '',
        ]);

        $this->assertSame([], $controller->buildFiltersForTest());
    }

    public static function invalidDateProvider(): array
    {
        return [
            'wrong order' => ['01-01-2026'],
            'missing zero padding' => ['2026-1-1'],
            'trailing characters' => ['2026-01-01x'],
            'leading characters' => ['x2026-01-01'],
            'datetime instead of date' => ['2026-01-01 10:00:00'],
            'words' => ['yesterday'],
            'slashes' => ['2026/01/01'],
        ];
    }

    #[DataProvider('invalidDateProvider')]
    public function testMalformedDatesAreDropped(string $value): void
    {
        $filters = $this->makeController([
            'date_from' => $value,
            'date_to' => $value,
        ])->buildFiltersForTest();

        $this->assertArrayNotHasKey('date_from', $filters);
        $this->assertArrayNotHasKey('date_to', $filters);
    }

    public function testWellFormedDatesAreKeptIndependently(): void
    {
        $filters = $this->makeController([
            'date_from' => '2026-01-31',
            'date_to' => 'not-a-date',
        ])->buildFiltersForTest();

        $this->assertSame(['date_from' => '2026-01-31'], $filters);
    }

    public function testFilterOrderIsStableForPaginationUrls(): void
    {
        // presentPagination() appends the filters in array order, so the query
        // string layout is part of the page's observable behaviour.
        $filters = $this->makeController([
            'date_to' => '2026-02-28',
            'event_type' => 'user_login',
            'date_from' => '2026-01-01',
            'name' => 'alice',
        ])->buildFiltersForTest();

        $this->assertSame(['name', 'event_type', 'date_from', 'date_to'], array_keys($filters));
    }

    public function testPageSpecificFilterParamsReplaceEventType(): void
    {
        // The zone log page filters on operation and user instead of event_type.
        $controller = $this->makeController([
            'name' => 'example.com',
            'operation' => 'zone_delete',
            'user' => 'bob',
            'event_type' => 'ignored-on-this-page',
        ]);
        $controller->additionalFilterParams = ['operation', 'user'];

        $this->assertSame([
            'name' => 'example.com',
            'operation' => 'zone_delete',
            'user' => 'bob',
        ], $controller->buildFiltersForTest());
    }

    public function testNameTransformHookIsApplied(): void
    {
        // The zone log page converts the name filter to punycode via this hook.
        $controller = $this->makeController(['name' => 'Example.COM']);
        $controller->nameTransform = strtolower(...);

        $this->assertSame(['name' => 'example.com'], $controller->buildFiltersForTest());
    }

    public function testPageBeyondTheEndClampsToTheLastPage(): void
    {
        $controller = $this->makeController();

        // 95 logs at 10 per page → 10 pages
        $this->assertSame(10, $controller->clampToLastPageForTest(999, 95, 10));
        $this->assertSame(10, $controller->clampToLastPageForTest(11, 95, 10));
    }

    public function testPagesWithinRangeAreUntouched(): void
    {
        $controller = $this->makeController();

        $this->assertSame(1, $controller->clampToLastPageForTest(1, 95, 10));
        $this->assertSame(10, $controller->clampToLastPageForTest(10, 95, 10));
        // Exact multiple: 100 logs at 10 per page is 10 pages, not 11
        $this->assertSame(10, $controller->clampToLastPageForTest(10, 100, 10));
    }

    public function testEmptyResultKeepsTheRequestedPage(): void
    {
        // Zero pages means nothing to clamp to; page 1 stays page 1.
        $controller = $this->makeController();

        $this->assertSame(1, $controller->clampToLastPageForTest(1, 0, 10));
    }

    public function testStructuredEventsAreSplitIntoColumnsForExport(): void
    {
        $parsed = $this->makeController()->parseLogEventsForTest([
            [
                'created_at' => '2026-01-01 10:00:00',
                'event' => 'operation:user_login user:alice status:success',
            ],
            [
                'created_at' => '2026-01-02 11:00:00',
                'event' => 'Free-form message without markers',
            ],
        ]);

        $this->assertSame([
            'timestamp' => '2026-01-01 10:00:00',
            'operation' => 'user_login',
            'user' => 'alice',
            'status' => 'success',
        ], $parsed[0]);
        $this->assertSame([
            'timestamp' => '2026-01-02 11:00:00',
            'event' => 'Free-form message without markers',
        ], $parsed[1]);
    }
}
