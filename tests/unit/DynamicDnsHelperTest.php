<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\DynamicDnsHelper;

class DynamicDnsHelperTest extends TestCase
{
    public function testStatusMessageWithoutVerbose(): void
    {
        $this->assertEquals("good\n", DynamicDnsHelper::statusMessage('good'));
    }

    public function testStatusMessageWithVerbose(): void
    {
        $this->assertEquals("Your hostname has been updated.\n", DynamicDnsHelper::statusMessage('good', true));
    }

    public function testStatusMessageWithVerboseMultipleWords(): void
    {
        $this->assertEquals("Your hostname has been updated.\n", DynamicDnsHelper::statusMessage('good 192.168.1.1', true));
    }

    public function testStatusMessageAllVerboseCodes(): void
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
            $this->assertEquals("$expected_message\n", DynamicDnsHelper::statusMessage((string)$code, true), "Wrong verbose message for code: $code");
        }
    }
}
