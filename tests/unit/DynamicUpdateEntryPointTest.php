<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;

class DynamicUpdateEntryPointTest extends TestCase
{
    public function testNoStoreIsSentBeforeAnyResponseIsProduced(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/dynamic_update.php');

        $header = strpos($source, "header('Cache-Control: no-store')");
        $this->assertNotFalse($header, 'dynamic_update.php must send Cache-Control: no-store');

        $firstResponse = strpos($source, 'http_response_code(');
        $this->assertNotFalse($firstResponse);
        $this->assertLessThan($firstResponse, $header);
        $this->assertLessThan((int)strpos($source, 'statusExit('), $header);
    }
}
