<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DynamicDnsHelper;

class DynamicDnsHelperTest extends TestCase
{
    public function testStatusExitWithoutVerbose(): void
    {
        ob_start();
        $result = DynamicDnsHelper::statusExit('good');
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertEquals("good\n", $output);
    }

    public function testStatusExitWithVerbose(): void
    {
        ob_start();
        $result = DynamicDnsHelper::statusExit('good', true);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertEquals("Your hostname has been updated.\n", $output);
    }

    public function testStatusExitWithVerboseMultipleWords(): void
    {
        ob_start();
        $result = DynamicDnsHelper::statusExit('good 192.168.1.1', true);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertEquals("Your hostname has been updated.\n", $output);
    }

    public function testStatusExitAllVerboseCodes(): void
    {
        $test_cases = [
            'badagent' => 'Your user agent is not valid.',
            'badauth' => 'Invalid username or password.  Authentication failed.',
            'notfqdn' => 'The hostname you specified was not valid.',
            'dnserr' => 'A DNS error has occurred on our end.  We apologize for any inconvenience.',
            '!yours' => 'The specified hostname does not belong to you.',
            'nohost' => 'The specified hostname does not exist.',
            'good' => 'Your hostname has been updated.',
            '911' => 'A critical error has occurred on our end.  We apologize for any inconvenience.',
            'nochg' => 'This update was identical to your last update, so no changes were made to your hostname configuration.',
            'baddbtype' => 'Unsupported database type',
        ];

        foreach ($test_cases as $code => $expected_message) {
            ob_start();
            $result = DynamicDnsHelper::statusExit((string)$code, true);
            $output = ob_get_clean();

            $this->assertFalse($result, "statusExit should always return false for code: $code");
            $this->assertEquals("$expected_message\n", $output, "Wrong verbose message for code: $code");
        }
    }
}
