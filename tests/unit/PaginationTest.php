<?php

namespace unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Pagination;

#[CoversClass(Pagination::class)]
class PaginationTest extends TestCase
{
    #[Test]
    public function constructorWithValidValues(): void
    {
        $pagination = new Pagination(100, 10, 1);

        $this->assertSame(100, $pagination->getTotalItems());
        $this->assertSame(10, $pagination->getItemsPerPage());
        $this->assertSame(1, $pagination->getCurrentPage());
    }

    #[Test]
    public function constructorThrowsForZeroItemsPerPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Items per page must be greater than zero.');

        new Pagination(100, 0, 1);
    }

    #[Test]
    public function constructorThrowsForNegativeItemsPerPage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Pagination(100, -5, 1);
    }

    #[Test]
    public function currentPageAdjustedToMinimumOne(): void
    {
        $pagination = new Pagination(100, 10, 0);
        $this->assertSame(1, $pagination->getCurrentPage());

        $pagination = new Pagination(100, 10, -5);
        $this->assertSame(1, $pagination->getCurrentPage());
    }

    #[Test]
    public function getNumberOfPagesCalculation(): void
    {
        $this->assertSame(10, (new Pagination(100, 10, 1))->getNumberOfPages());
        $this->assertSame(4, (new Pagination(31, 10, 1))->getNumberOfPages());
        $this->assertSame(1, (new Pagination(5, 10, 1))->getNumberOfPages());
        $this->assertSame(0, (new Pagination(0, 10, 1))->getNumberOfPages());
    }

    #[Test]
    public function getOffsetCalculation(): void
    {
        $this->assertSame(0, (new Pagination(100, 10, 1))->getOffset());
        $this->assertSame(10, (new Pagination(100, 10, 2))->getOffset());
        $this->assertSame(90, (new Pagination(100, 10, 10))->getOffset());
        $this->assertSame(0, (new Pagination(100, 25, 1))->getOffset());
        $this->assertSame(25, (new Pagination(100, 25, 2))->getOffset());
    }

    #[Test]
    public function getLimitReturnsItemsPerPage(): void
    {
        $this->assertSame(10, (new Pagination(100, 10, 1))->getLimit());
        $this->assertSame(25, (new Pagination(100, 25, 1))->getLimit());
    }

    #[Test]
    public function isFirstPage(): void
    {
        $this->assertTrue((new Pagination(100, 10, 1))->isFirstPage());
        $this->assertFalse((new Pagination(100, 10, 2))->isFirstPage());
        $this->assertFalse((new Pagination(100, 10, 10))->isFirstPage());
    }

    #[Test]
    public function isLastPage(): void
    {
        $this->assertTrue((new Pagination(100, 10, 10))->isLastPage());
        $this->assertFalse((new Pagination(100, 10, 1))->isLastPage());
        $this->assertFalse((new Pagination(100, 10, 5))->isLastPage());
    }

    #[Test]
    public function hasNextPage(): void
    {
        $this->assertTrue((new Pagination(100, 10, 1))->hasNextPage());
        $this->assertTrue((new Pagination(100, 10, 9))->hasNextPage());
        $this->assertFalse((new Pagination(100, 10, 10))->hasNextPage());
    }

    #[Test]
    public function hasPreviousPage(): void
    {
        $this->assertFalse((new Pagination(100, 10, 1))->hasPreviousPage());
        $this->assertTrue((new Pagination(100, 10, 2))->hasPreviousPage());
        $this->assertTrue((new Pagination(100, 10, 10))->hasPreviousPage());
    }

    #[Test]
    public function getNextPage(): void
    {
        $this->assertSame(2, (new Pagination(100, 10, 1))->getNextPage());
        $this->assertSame(10, (new Pagination(100, 10, 10))->getNextPage());
    }

    #[Test]
    public function getPreviousPage(): void
    {
        $this->assertSame(1, (new Pagination(100, 10, 1))->getPreviousPage());
        $this->assertSame(1, (new Pagination(100, 10, 2))->getPreviousPage());
        $this->assertSame(9, (new Pagination(100, 10, 10))->getPreviousPage());
    }

    #[Test]
    public function getStartPageWindowCentering(): void
    {
        $pagination = new Pagination(200, 10, 10);
        $this->assertSame(8, $pagination->getStartPage(5));

        $pagination = new Pagination(200, 10, 1);
        $this->assertSame(1, $pagination->getStartPage(5));

        $pagination = new Pagination(200, 10, 20);
        $this->assertSame(16, $pagination->getStartPage(5));
    }

    #[Test]
    public function getStartPageWhenFewPages(): void
    {
        $pagination = new Pagination(30, 10, 2);
        $this->assertSame(1, $pagination->getStartPage(5));
    }

    #[Test]
    public function getEndPage(): void
    {
        $pagination = new Pagination(200, 10, 10);
        $this->assertSame(12, $pagination->getEndPage(5));

        $pagination = new Pagination(200, 10, 1);
        $this->assertSame(5, $pagination->getEndPage(5));

        $pagination = new Pagination(200, 10, 20);
        $this->assertSame(20, $pagination->getEndPage(5));
    }

    #[Test]
    public function zeroTotalItems(): void
    {
        $pagination = new Pagination(0, 10, 1);

        $this->assertSame(0, $pagination->getTotalItems());
        $this->assertSame(0, $pagination->getNumberOfPages());
        $this->assertFalse($pagination->hasNextPage());
        $this->assertFalse($pagination->hasPreviousPage());
        $this->assertSame(0, $pagination->getOffset());
    }

    #[Test]
    public function singleItem(): void
    {
        $pagination = new Pagination(1, 10, 1);

        $this->assertSame(1, $pagination->getNumberOfPages());
        $this->assertTrue($pagination->isFirstPage());
        $this->assertTrue($pagination->isLastPage());
        $this->assertFalse($pagination->hasNextPage());
    }

    #[Test]
    public function exactlyFullPage(): void
    {
        $pagination = new Pagination(10, 10, 1);

        $this->assertSame(1, $pagination->getNumberOfPages());
        $this->assertTrue($pagination->isFirstPage());
        $this->assertTrue($pagination->isLastPage());
    }
}
