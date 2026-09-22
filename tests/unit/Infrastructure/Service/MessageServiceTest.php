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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Session\PhpSession;
use Poweradmin\Domain\Service\Auth\UserContextService;

#[CoversClass(MessageService::class)]
class MessageServiceTest extends TestCase
{
    private MessageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Initialize session
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        // Clear any existing messages
        $_SESSION['messages'] = [];

        $this->service = new MessageService(new UserContextService(new PhpSession()));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    // ========== addMessage tests ==========

    #[Test]
    public function testAddMessageStoresMessageInSession(): void
    {
        $this->service->addMessage('test_script', 'info', 'Test message');

        $this->assertArrayHasKey('test_script', $_SESSION['messages']);
        $this->assertCount(1, $_SESSION['messages']['test_script']);
        $this->assertEquals('info', $_SESSION['messages']['test_script'][0]['type']);
        $this->assertEquals('Test message', $_SESSION['messages']['test_script'][0]['content']);
    }

    #[Test]
    public function testAddMessageWithRecordNameAppendsContext(): void
    {
        $this->service->addMessage('test_script', 'error', 'Error occurred', 'record123');

        $this->assertStringContainsString('record123', $_SESSION['messages']['test_script'][0]['content']);
    }

    #[Test]
    public function testAddMessagePreventsDuplicates(): void
    {
        $this->service->addMessage('test_script', 'info', 'Same message');
        $this->service->addMessage('test_script', 'info', 'Same message');
        $this->service->addMessage('test_script', 'info', 'Same message');

        $this->assertCount(1, $_SESSION['messages']['test_script']);
    }

    #[Test]
    public function testAddMessageAllowsDifferentTypesWithSameContent(): void
    {
        $this->service->addMessage('test_script', 'info', 'Same message');
        $this->service->addMessage('test_script', 'error', 'Same message');

        $this->assertCount(2, $_SESSION['messages']['test_script']);
    }

    #[Test]
    public function testAddMessageAllowsDifferentContentWithSameType(): void
    {
        $this->service->addMessage('test_script', 'info', 'Message 1');
        $this->service->addMessage('test_script', 'info', 'Message 2');

        $this->assertCount(2, $_SESSION['messages']['test_script']);
    }

    // ========== getMessages tests ==========

    #[Test]
    public function testGetMessagesReturnsAndClearsMessages(): void
    {
        $this->service->addMessage('test_script', 'info', 'Message 1');
        $this->service->addMessage('test_script', 'error', 'Message 2');

        $messages = $this->service->getMessages('test_script');

        $this->assertCount(2, $messages);
        $this->assertArrayNotHasKey('test_script', $_SESSION['messages']);
    }

    #[Test]
    public function testGetMessagesReturnsNullWhenNoMessages(): void
    {
        $messages = $this->service->getMessages('nonexistent_script');

        $this->assertNull($messages);
    }

    // ========== addSystemError tests ==========

    #[Test]
    public function testAddSystemErrorAddsToSystemScript(): void
    {
        $this->service->addSystemError('System error occurred');

        $this->assertArrayHasKey('system', $_SESSION['messages']);
        $this->assertEquals('error', $_SESSION['messages']['system'][0]['type']);
    }

    // ========== Multiple scripts tests ==========

    #[Test]
    public function testMessagesAreSeparatedByScript(): void
    {
        $this->service->addMessage('script1', 'error', 'Error in script 1');
        $this->service->addMessage('script2', 'info', 'Info in script 2');

        $script1Messages = $this->service->getMessages('script1');
        $script2Messages = $this->service->getMessages('script2');

        $this->assertCount(1, $script1Messages);
        $this->assertEquals('error', $script1Messages[0]['type']);

        $this->assertCount(1, $script2Messages);
        $this->assertEquals('info', $script2Messages[0]['type']);
    }

    // ========== Direct system error tests ==========

    #[Test]
    public function testDirectSystemErrorReportsServerError(): void
    {
        ob_start();
        $this->service->displayDirectSystemError('Configuration is broken', false);
        $body = (string)ob_get_clean();

        $this->assertSame(500, http_response_code());
        $this->assertStringContainsString('<!DOCTYPE html>', $body);
        $this->assertStringContainsString('Configuration is broken', $body);

        http_response_code(200);
    }

    #[Test]
    public function testDirectSystemErrorEscapesTheMessage(): void
    {
        ob_start();
        $this->service->displayDirectSystemError('<script>alert(1)</script>', false);
        $body = (string)ob_get_clean();

        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringNotContainsString('<script>alert', $body);

        http_response_code(200);
    }
}
