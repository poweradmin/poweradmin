<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\BulkRegistrationController;

class BulkRegistrationLineLimitTest extends TestCase
{
    public function testAtLimitIsAccepted(): void
    {
        $input = implode("\n", array_fill(0, BulkRegistrationController::MAX_LINES, 'example.com'));
        $this->assertFalse(BulkRegistrationController::exceedsLineLimit($input));
    }

    public function testAboveLimitIsRefused(): void
    {
        $input = implode("\r\n", array_fill(0, BulkRegistrationController::MAX_LINES + 1, 'example.com'));
        $this->assertTrue(BulkRegistrationController::exceedsLineLimit($input));
    }

    public function testBlankLinesAreNotCounted(): void
    {
        $input = implode("\n\n", array_fill(0, BulkRegistrationController::MAX_LINES, 'example.com'));
        $this->assertFalse(BulkRegistrationController::exceedsLineLimit($input));
    }
}
