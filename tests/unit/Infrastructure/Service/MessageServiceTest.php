<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
        $_SESSION['form_data'] = [];

        $this->service = new MessageService();
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

    // ========== addError tests ==========

    #[Test]
    public function testAddErrorAddsErrorTypeMessage(): void
    {
        $this->service->addError('test_script', 'Error message');

        $this->assertEquals('error', $_SESSION['messages']['test_script'][0]['type']);
    }

    #[Test]
    public function testAddErrorWithRecordName(): void
    {
        $this->service->addError('test_script', 'Error message', 'rec1');

        $this->assertStringContainsString('rec1', $_SESSION['messages']['test_script'][0]['content']);
    }

    // ========== addWarning tests ==========

    #[Test]
    public function testAddWarningAddsWarnTypeMessage(): void
    {
        $this->service->addWarning('test_script', 'Warning message');

        $this->assertEquals('warn', $_SESSION['messages']['test_script'][0]['type']);
    }

    // ========== addSuccess tests ==========

    #[Test]
    public function testAddSuccessAddsSuccessTypeMessage(): void
    {
        $this->service->addSuccess('test_script', 'Success message');

        $this->assertEquals('success', $_SESSION['messages']['test_script'][0]['type']);
    }

    // ========== addInfo tests ==========

    #[Test]
    public function testAddInfoAddsInfoTypeMessage(): void
    {
        $this->service->addInfo('test_script', 'Info message');

        $this->assertEquals('info', $_SESSION['messages']['test_script'][0]['type']);
    }

    // ========== getMessages tests ==========

    #[Test]
    public function testGetMessagesReturnsAndClearsMessages(): void
    {
        $this->service->addInfo('test_script', 'Message 1');
        $this->service->addError('test_script', 'Message 2');

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

    // ========== renderMessages tests ==========

    #[Test]
    public function testRenderMessagesReturnsHtmlWithAlert(): void
    {
        $this->service->addError('test', 'Error message');

        $html = $this->service->renderMessages('test.twig');

        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('Error message', $html);
    }

    #[Test]
    public function testRenderMessagesReturnsEmptyStringWhenNoMessages(): void
    {
        $html = $this->service->renderMessages('test.twig');

        $this->assertEquals('', $html);
    }

    #[Test]
    public function testRenderMessagesShowsCorrectIconForSuccess(): void
    {
        $this->service->addSuccess('test', 'Success message');

        $html = $this->service->renderMessages('test.twig');

        $this->assertStringContainsString('alert-success', $html);
        $this->assertStringContainsString('check-circle', $html);
    }

    #[Test]
    public function testRenderMessagesShowsCorrectIconForWarning(): void
    {
        $this->service->addWarning('test', 'Warning message');

        $html = $this->service->renderMessages('test.twig');

        $this->assertStringContainsString('alert-warning', $html);
    }

    #[Test]
    public function testRenderMessagesShowsCorrectIconForInfo(): void
    {
        $this->service->addInfo('test', 'Info message');

        $html = $this->service->renderMessages('test.twig');

        $this->assertStringContainsString('alert-info', $html);
        $this->assertStringContainsString('info-circle', $html);
    }

    // ========== generateFormToken tests ==========

    #[Test]
    public function testGenerateFormTokenReturnsHexString(): void
    {
        $token = $this->service->generateFormToken();

        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $token);
    }

    #[Test]
    public function testGenerateFormTokenReturns32CharString(): void
    {
        $token = $this->service->generateFormToken();

        $this->assertEquals(32, strlen($token));
    }

    #[Test]
    public function testGenerateFormTokenReturnsUniqueTokens(): void
    {
        $token1 = $this->service->generateFormToken();
        $token2 = $this->service->generateFormToken();

        $this->assertNotEquals($token1, $token2);
    }

    // ========== storeFormData tests ==========

    #[Test]
    public function testStoreFormDataStoresInSession(): void
    {
        $token = 'test_token_123';
        $data = ['field1' => 'value1', 'field2' => 'value2'];

        $this->service->storeFormData($token, $data);

        $this->assertArrayHasKey($token, $_SESSION['form_data']);
        $this->assertEquals($data, $_SESSION['form_data'][$token]['data']);
    }

    #[Test]
    public function testStoreFormDataSetsExpiration(): void
    {
        $token = 'test_token_123';
        $data = ['field1' => 'value1'];

        $this->service->storeFormData($token, $data);

        $this->assertArrayHasKey('expires', $_SESSION['form_data'][$token]);
        $this->assertGreaterThan(time(), $_SESSION['form_data'][$token]['expires']);
    }

    // ========== getFormData tests ==========

    #[Test]
    public function testGetFormDataReturnsStoredData(): void
    {
        $token = 'test_token_123';
        $data = ['field1' => 'value1'];

        $this->service->storeFormData($token, $data);
        $retrieved = $this->service->getFormData($token);

        $this->assertEquals($data, $retrieved);
    }

    #[Test]
    public function testGetFormDataRemovesDataAfterRetrieval(): void
    {
        $token = 'test_token_123';
        $data = ['field1' => 'value1'];

        $this->service->storeFormData($token, $data);
        $this->service->getFormData($token);
        $secondRetrieval = $this->service->getFormData($token);

        $this->assertNull($secondRetrieval);
    }

    #[Test]
    public function testGetFormDataReturnsNullForNonexistentToken(): void
    {
        $result = $this->service->getFormData('nonexistent_token');

        $this->assertNull($result);
    }

    #[Test]
    public function testGetFormDataReturnsNullForExpiredData(): void
    {
        $token = 'test_token_123';

        // Manually set expired data
        $_SESSION['form_data'][$token] = [
            'data' => ['field1' => 'value1'],
            'expires' => time() - 100 // Already expired
        ];

        $result = $this->service->getFormData($token);

        $this->assertNull($result);
    }

    // ========== cleanupFormData tests ==========

    #[Test]
    public function testCleanupFormDataRemovesExpiredEntries(): void
    {
        $_SESSION['form_data'] = [
            'expired_token' => [
                'data' => ['field1' => 'value1'],
                'expires' => time() - 100
            ],
            'valid_token' => [
                'data' => ['field2' => 'value2'],
                'expires' => time() + 300
            ]
        ];

        $this->service->cleanupFormData();

        $this->assertArrayNotHasKey('expired_token', $_SESSION['form_data']);
        $this->assertArrayHasKey('valid_token', $_SESSION['form_data']);
    }

    #[Test]
    public function testCleanupFormDataHandlesEmptySession(): void
    {
        unset($_SESSION['form_data']);

        // Should not throw an exception
        $this->service->cleanupFormData();

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    // ========== Method chaining tests ==========

    #[Test]
    public function testWithRecordContextReturnsInstance(): void
    {
        $result = $this->service->withRecordContext('record1');

        $this->assertInstanceOf(MessageService::class, $result);
    }

    #[Test]
    public function testAllowHtmlReturnsInstance(): void
    {
        $result = $this->service->allowHtml();

        $this->assertInstanceOf(MessageService::class, $result);
    }

    #[Test]
    public function testDontExitReturnsInstance(): void
    {
        $result = $this->service->dontExit();

        $this->assertInstanceOf(MessageService::class, $result);
    }

    // ========== Multiple scripts tests ==========

    #[Test]
    public function testMessagesAreSeparatedByScript(): void
    {
        $this->service->addError('script1', 'Error in script 1');
        $this->service->addInfo('script2', 'Info in script 2');

        $script1Messages = $this->service->getMessages('script1');
        $script2Messages = $this->service->getMessages('script2');

        $this->assertCount(1, $script1Messages);
        $this->assertEquals('error', $script1Messages[0]['type']);

        $this->assertCount(1, $script2Messages);
        $this->assertEquals('info', $script2Messages[0]['type']);
    }
}
