<?php

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\PaginationService;

class PaginationServiceTest extends TestCase
{
    public function testOffsetIsAnIntForHugeAndNonPositivePages(): void
    {
        $this->assertSame(0, PaginationService::offset(1, 25));
        $this->assertSame(50, PaginationService::offset(3, 25));
        $this->assertSame(0, PaginationService::offset(0, 25));
        $this->assertSame(0, PaginationService::offset(PHP_INT_MIN, 25));
        $this->assertSame(2147483647, PaginationService::offset(PHP_INT_MAX, 25));
        $this->assertSame(2147483647, PaginationService::offset(PHP_INT_MAX, PHP_INT_MAX));
    }
}
