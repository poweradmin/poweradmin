<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\FormStateService;

/**
 * Unit tests for FormStateService
 *
 * @covers \Poweradmin\Domain\Service\FormStateService
 */
class FormStateServiceTest extends TestCase
{
    /**
     * @var FormStateService
     */
    private $service;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize the session array if it's not already set
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        $this->service = new FormStateService();
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        // Clear session data after each test
        $_SESSION = [];

        parent::tearDown();
    }

    /**
     * @test
     * Test that form IDs are generated with the correct format and prefix
     */
    public function testGenerateFormId(): void
    {
        // Test with default parameter (empty prefix)
        $formId = $this->service->generateFormId();
        $this->assertMatchesRegularExpression('/^_[0-9a-f]{16}$/', $formId, 'Default form ID should have correct format');

        // Test with custom prefix
        $prefix = 'test_prefix';
        $formIdWithPrefix = $this->service->generateFormId($prefix);
        $this->assertMatchesRegularExpression('/^test_prefix_[0-9a-f]{16}$/', $formIdWithPrefix, 'Prefixed form ID should have correct format');

        // Test that generated IDs are unique
        $anotherFormId = $this->service->generateFormId();
        $this->assertNotEquals($formId, $anotherFormId, 'Generated form IDs should be unique');
    }

    /**
     * @test
     * Test saving and retrieving form data
     */
    public function testSaveAndGetFormData(): void
    {
        $formId = 'test_form_123';
        $testData = ['field1' => 'value1', 'field2' => 'value2'];

        // Save the data
        $this->service->saveFormData($formId, $testData);

        // Verify data was saved to session
        $this->assertArrayHasKey('form_state', $_SESSION, 'Session should have form_state key');
        $this->assertArrayHasKey($formId, $_SESSION['form_state'], 'Session should have the form ID key');
        $this->assertArrayHasKey('data', $_SESSION['form_state'][$formId], 'Form state should have data key');
        $this->assertArrayHasKey('expires', $_SESSION['form_state'][$formId], 'Form state should have expires key');

        // Retrieve and verify the data
        $retrievedData = $this->service->getFormData($formId);
        $this->assertEquals($testData, $retrievedData, 'Retrieved data should match saved data');

        // Check that data is still in session after retrieval (not removed)
        $this->assertArrayHasKey($formId, $_SESSION['form_state'], 'Form data should remain in session after retrieval');
    }

    /**
     * @test
     * Test that form data can be explicitly cleared
     */
    public function testClearFormData(): void
    {
        $formId = 'test_form_456';
        $testData = ['field1' => 'value1', 'field2' => 'value2'];

        // Save the data
        $this->service->saveFormData($formId, $testData);

        // Verify data was saved
        $this->assertArrayHasKey($formId, $_SESSION['form_state']);

        // Clear the data
        $this->service->clearFormData($formId);

        // Verify data was removed
        $this->assertArrayNotHasKey($formId, $_SESSION['form_state'], 'Form data should be removed after clearFormData');

        // Verify getFormData returns null after clearing
        $retrievedData = $this->service->getFormData($formId);
        $this->assertNull($retrievedData, 'getFormData should return null after data is cleared');
    }

    /**
     * @test
     * Test that expired form data is automatically cleaned up
     */
    public function testAutoCleanupExpiredData(): void
    {
        $expiredFormId = 'expired_form';
        $validFormId = 'valid_form';
        $testData = ['field' => 'value'];

        // Manual setup of expired data in session
        $_SESSION['form_state'][$expiredFormId] = [
            'data' => $testData,
            'expires' => time() - 10 // 10 seconds in the past
        ];

        // Set up valid data
        $this->service->saveFormData($validFormId, $testData);

        // Try to retrieve expired data - this should trigger cleanup
        $retrievedExpiredData = $this->service->getFormData($expiredFormId);

        // Verify expired data is removed and returns null
        $this->assertNull($retrievedExpiredData, 'Expired data should not be retrievable');
        $this->assertArrayNotHasKey($expiredFormId, $_SESSION['form_state'], 'Expired data should be removed from session');

        // Verify valid data is still accessible
        $retrievedValidData = $this->service->getFormData($validFormId);
        $this->assertEquals($testData, $retrievedValidData, 'Valid data should still be retrievable');
    }

    /**
     * @test
     * Test expiry time refreshes on data retrieval
     */
    public function testExpiryTimeRefreshOnRetrieval(): void
    {
        $formId = 'refresh_test_form';
        $testData = ['field' => 'test_value'];

        // Save data with a specific expiry time
        $_SESSION['form_state'][$formId] = [
            'data' => $testData,
            'expires' => $originalExpiry = time() + 100 // 100 seconds in the future
        ];

        // Small delay to ensure time difference
        usleep(1000); // 1 millisecond

        // Retrieve the data, which should refresh the expiry
        $this->service->getFormData($formId);

        // Verify expiry time was updated
        $this->assertGreaterThan(
            $originalExpiry,
            $_SESSION['form_state'][$formId]['expires'],
            'Expiry time should be refreshed after retrieval'
        );
    }

    /**
     * @test
     * Test handling non-existent form IDs
     */
    public function testGetNonExistentFormData(): void
    {
        $nonExistentFormId = 'non_existent_form';

        // Try to get data for a form that doesn't exist
        $result = $this->service->getFormData($nonExistentFormId);

        // Should return null
        $this->assertNull($result, 'Getting non-existent form data should return null');
    }

    /**
     * @test
     * Test handling empty session
     */
    public function testEmptySession(): void
    {
        // Ensure SESSION is empty
        $_SESSION = [];

        // Try to get data with empty session
        $result = $this->service->getFormData('any_form_id');

        // Should return null without errors
        $this->assertNull($result, 'Getting form data with empty session should return null');
    }

    /**
     * @test
     * Test saving multiple form data items
     */
    public function testSaveMultipleFormData(): void
    {
        $formId1 = 'form_1';
        $formId2 = 'form_2';
        $data1 = ['name' => 'John'];
        $data2 = ['name' => 'Jane'];

        // Save multiple forms
        $this->service->saveFormData($formId1, $data1);
        $this->service->saveFormData($formId2, $data2);

        // Verify both are saved correctly
        $this->assertEquals($data1, $this->service->getFormData($formId1));
        $this->assertEquals($data2, $this->service->getFormData($formId2));
    }

    /**
     * @test
     * Test clearing non-existent form ID
     */
    public function testClearNonExistentFormData(): void
    {
        // Try to clear a form that doesn't exist (should not cause errors)
        $this->service->clearFormData('non_existent_form');

        // This is essentially testing that no exception is thrown
        $this->assertTrue(true);
    }
}
