<?php

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\ResultPaginator;

#[CoversClass(ResultPaginator::class)]
class ResultPaginatorTest extends TestCase
{
    private array $sampleData;

    protected function setUp(): void
    {
        $this->sampleData = [
            ['name' => 'charlie.com', 'type' => 'NATIVE', 'id' => 3],
            ['name' => 'alpha.com', 'type' => 'MASTER', 'id' => 1],
            ['name' => 'bravo.com', 'type' => 'SLAVE', 'id' => 2],
            ['name' => 'delta.com', 'type' => 'NATIVE', 'id' => 4],
        ];
    }

    // ---------------------------------------------------------------
    // sort()
    // ---------------------------------------------------------------

    public function testSortAscByName(): void
    {
        $result = ResultPaginator::sort($this->sampleData, 'name', 'ASC');

        $this->assertSame('alpha.com', $result[0]['name']);
        $this->assertSame('bravo.com', $result[1]['name']);
        $this->assertSame('charlie.com', $result[2]['name']);
        $this->assertSame('delta.com', $result[3]['name']);
    }

    public function testSortDescByName(): void
    {
        $result = ResultPaginator::sort($this->sampleData, 'name', 'DESC');

        $this->assertSame('delta.com', $result[0]['name']);
        $this->assertSame('charlie.com', $result[1]['name']);
        $this->assertSame('bravo.com', $result[2]['name']);
        $this->assertSame('alpha.com', $result[3]['name']);
    }

    public function testSortByNumericField(): void
    {
        $result = ResultPaginator::sort($this->sampleData, 'id', 'ASC');

        $this->assertSame(1, $result[0]['id']);
        $this->assertSame(2, $result[1]['id']);
        $this->assertSame(3, $result[2]['id']);
        $this->assertSame(4, $result[3]['id']);
    }

    public function testSortEmptyArray(): void
    {
        $result = ResultPaginator::sort([], 'name');
        $this->assertSame([], $result);
    }

    public function testSortEmptySortBy(): void
    {
        $result = ResultPaginator::sort($this->sampleData, '');
        $this->assertSame($this->sampleData, $result);
    }

    public function testSortByMissingKey(): void
    {
        $result = ResultPaginator::sort($this->sampleData, 'nonexistent');
        $this->assertCount(4, $result);
    }

    // ---------------------------------------------------------------
    // filterByLetter()
    // ---------------------------------------------------------------

    public function testFilterByLetterA(): void
    {
        $result = ResultPaginator::filterByLetter($this->sampleData, 'a');

        $this->assertCount(1, $result);
        $this->assertSame('alpha.com', $result[0]['name']);
    }

    public function testFilterByLetterCaseInsensitive(): void
    {
        $result = ResultPaginator::filterByLetter($this->sampleData, 'C');

        $this->assertCount(1, $result);
        $this->assertSame('charlie.com', $result[0]['name']);
    }

    public function testFilterByLetterAll(): void
    {
        $result = ResultPaginator::filterByLetter($this->sampleData, 'all');
        $this->assertCount(4, $result);
    }

    public function testFilterByLetterEmpty(): void
    {
        $result = ResultPaginator::filterByLetter($this->sampleData, '');
        $this->assertCount(4, $result);
    }

    public function testFilterByLetterDigit(): void
    {
        $data = [
            ['name' => '1example.com'],
            ['name' => '2test.com'],
            ['name' => 'alpha.com'],
        ];

        $result = ResultPaginator::filterByLetter($data, '1');

        $this->assertCount(2, $result);
        $this->assertSame('1example.com', $result[0]['name']);
        $this->assertSame('2test.com', $result[1]['name']);
    }

    public function testFilterByLetterNoMatch(): void
    {
        $result = ResultPaginator::filterByLetter($this->sampleData, 'z');
        $this->assertCount(0, $result);
    }

    public function testFilterByLetterCustomField(): void
    {
        $result = ResultPaginator::filterByLetter($this->sampleData, 'n', 'type');

        $this->assertCount(2, $result);
        $this->assertSame('NATIVE', $result[0]['type']);
    }

    // ---------------------------------------------------------------
    // filterByPattern()
    // ---------------------------------------------------------------

    public function testFilterByPatternSubstring(): void
    {
        $result = ResultPaginator::filterByPattern($this->sampleData, 'alpha', ['name']);

        $this->assertCount(1, $result);
        $this->assertSame('alpha.com', $result[0]['name']);
    }

    public function testFilterByPatternCaseInsensitive(): void
    {
        $result = ResultPaginator::filterByPattern($this->sampleData, 'BRAVO', ['name']);

        $this->assertCount(1, $result);
        $this->assertSame('bravo.com', $result[0]['name']);
    }

    public function testFilterByPatternMultipleFields(): void
    {
        $result = ResultPaginator::filterByPattern($this->sampleData, 'native', ['name', 'type']);

        $this->assertCount(2, $result);
    }

    public function testFilterByPatternEmptyString(): void
    {
        $result = ResultPaginator::filterByPattern($this->sampleData, '', ['name']);
        $this->assertCount(4, $result);
    }

    public function testFilterByPatternEmptyFields(): void
    {
        $result = ResultPaginator::filterByPattern($this->sampleData, 'test', []);
        $this->assertCount(4, $result);
    }

    public function testFilterByPatternCommon(): void
    {
        $result = ResultPaginator::filterByPattern($this->sampleData, '.com', ['name']);
        $this->assertCount(4, $result);
    }

    // ---------------------------------------------------------------
    // filterByValue()
    // ---------------------------------------------------------------

    public function testFilterByValueExact(): void
    {
        $result = ResultPaginator::filterByValue($this->sampleData, 'type', 'NATIVE');

        $this->assertCount(2, $result);
        $this->assertSame('charlie.com', $result[0]['name']);
        $this->assertSame('delta.com', $result[1]['name']);
    }

    public function testFilterByValueCaseInsensitive(): void
    {
        $result = ResultPaginator::filterByValue($this->sampleData, 'type', 'master');
        $this->assertCount(1, $result);
    }

    public function testFilterByValueEmpty(): void
    {
        $result = ResultPaginator::filterByValue($this->sampleData, 'type', '');
        $this->assertCount(4, $result);
    }

    public function testFilterByValueNoMatch(): void
    {
        $result = ResultPaginator::filterByValue($this->sampleData, 'type', 'UNKNOWN');
        $this->assertCount(0, $result);
    }

    // ---------------------------------------------------------------
    // paginate()
    // ---------------------------------------------------------------

    public function testPaginateFirstPage(): void
    {
        $result = ResultPaginator::paginate($this->sampleData, 0, 2);

        $this->assertCount(2, $result);
        $this->assertSame('charlie.com', $result[0]['name']);
        $this->assertSame('alpha.com', $result[1]['name']);
    }

    public function testPaginateSecondPage(): void
    {
        $result = ResultPaginator::paginate($this->sampleData, 2, 2);

        $this->assertCount(2, $result);
        $this->assertSame('bravo.com', $result[0]['name']);
        $this->assertSame('delta.com', $result[1]['name']);
    }

    public function testPaginateBeyondEnd(): void
    {
        $result = ResultPaginator::paginate($this->sampleData, 10, 2);
        $this->assertCount(0, $result);
    }

    public function testPaginatePartialPage(): void
    {
        $result = ResultPaginator::paginate($this->sampleData, 3, 5);
        $this->assertCount(1, $result);
    }

    // ---------------------------------------------------------------
    // getDistinctLetters()
    // ---------------------------------------------------------------

    public function testGetDistinctLetters(): void
    {
        $result = ResultPaginator::getDistinctLetters($this->sampleData);

        $this->assertSame(['a', 'b', 'c', 'd'], $result);
    }

    public function testGetDistinctLettersCustomField(): void
    {
        $result = ResultPaginator::getDistinctLetters($this->sampleData, 'type');

        $this->assertSame(['m', 'n', 's'], $result);
    }

    public function testGetDistinctLettersEmpty(): void
    {
        $result = ResultPaginator::getDistinctLetters([]);
        $this->assertSame([], $result);
    }

    public function testGetDistinctLettersWithNumbers(): void
    {
        $data = [
            ['name' => '1test.com'],
            ['name' => 'alpha.com'],
            ['name' => '2test.com'],
        ];

        $result = ResultPaginator::getDistinctLetters($data);
        // PHP coerces numeric string array keys to integers
        $this->assertSame([1, 2, 'a'], $result);
    }

    public function testGetDistinctLettersDeduplicates(): void
    {
        $data = [
            ['name' => 'alpha.com'],
            ['name' => 'another.com'],
            ['name' => 'awesome.com'],
        ];

        $result = ResultPaginator::getDistinctLetters($data);
        $this->assertSame(['a'], $result);
    }

    // ---------------------------------------------------------------
    // Integration: sort + filter + paginate pipeline
    // ---------------------------------------------------------------

    public function testSortFilterPaginatePipeline(): void
    {
        $data = [];
        for ($i = 1; $i <= 20; $i++) {
            $data[] = ['name' => "zone{$i}.com", 'type' => $i % 2 === 0 ? 'NATIVE' : 'MASTER', 'id' => $i];
        }

        $filtered = ResultPaginator::filterByValue($data, 'type', 'NATIVE');
        $this->assertCount(10, $filtered);

        $sorted = ResultPaginator::sort($filtered, 'name', 'ASC');
        // strnatcasecmp sorts naturally: zone2 < zone10
        $this->assertSame('zone2.com', $sorted[0]['name']);

        $page = ResultPaginator::paginate($sorted, 0, 3);
        $this->assertCount(3, $page);
    }
}
