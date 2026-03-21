<?php

namespace unit\Api;

use PHPUnit\Framework\TestCase;

class ApiInputHelperTest extends TestCase
{
    private TestableApiInputHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new TestableApiInputHelper();
    }

    // =========================================================================
    // inputString
    // =========================================================================

    public function testInputStringReturnsStringValue(): void
    {
        $this->assertSame('hello', $this->helper->callInputString(['key' => 'hello'], 'key'));
    }

    public function testInputStringReturnsEmptyString(): void
    {
        $this->assertSame('', $this->helper->callInputString(['key' => ''], 'key'));
    }

    public function testInputStringReturnsDefaultForMissingKey(): void
    {
        $this->assertSame('default', $this->helper->callInputString([], 'key', 'default'));
    }

    public function testInputStringReturnsNullForInvalidType(): void
    {
        // Present but wrong type -> null (not default)
        $this->assertNull($this->helper->callInputString(['key' => 42], 'key', 'default'));
    }

    public function testInputStringRejectsArray(): void
    {
        $this->assertNull($this->helper->callInputString(['key' => [1, 2]], 'key', 'default'));
    }

    public function testInputStringRejectsBooleanTrue(): void
    {
        $this->assertNull($this->helper->callInputString(['key' => true], 'key', 'default'));
    }

    public function testInputStringRejectsNull(): void
    {
        $this->assertNull($this->helper->callInputString(['key' => null], 'key', 'default'));
    }

    // =========================================================================
    // inputInt
    // =========================================================================

    public function testInputIntReturnsIntValue(): void
    {
        $this->assertSame(42, $this->helper->callInputInt(['key' => 42], 'key'));
    }

    public function testInputIntReturnsZero(): void
    {
        $this->assertSame(0, $this->helper->callInputInt(['key' => 0], 'key'));
    }

    public function testInputIntAcceptsNumericString(): void
    {
        $this->assertSame(42, $this->helper->callInputInt(['key' => '42'], 'key'));
    }

    public function testInputIntReturnsDefaultForMissingKey(): void
    {
        $this->assertSame(100, $this->helper->callInputInt([], 'key', 100));
    }

    public function testInputIntReturnsNullForInvalidType(): void
    {
        // Present but wrong type -> null (not default), enables 400 response
        $this->assertNull($this->helper->callInputInt(['key' => 'abc'], 'key', 100));
    }

    public function testInputIntRejectsArray(): void
    {
        $this->assertNull($this->helper->callInputInt(['key' => [1, 2]], 'key', 0));
    }

    public function testInputIntRejectsBooleanTrue(): void
    {
        $this->assertNull($this->helper->callInputInt(['key' => true], 'key', 0));
    }

    public function testInputIntRejectsNonNumericString(): void
    {
        $this->assertNull($this->helper->callInputInt(['key' => 'abc'], 'key', 0));
    }

    // =========================================================================
    // inputBool
    // =========================================================================

    public function testInputBoolReturnsTrue(): void
    {
        $this->assertTrue($this->helper->callInputBool(['key' => true], 'key'));
    }

    public function testInputBoolReturnsFalse(): void
    {
        $this->assertFalse($this->helper->callInputBool(['key' => false], 'key'));
    }

    public function testInputBoolReturnsDefaultForMissingKey(): void
    {
        $this->assertFalse($this->helper->callInputBool([], 'key', false));
    }

    public function testInputBoolAcceptsInt1(): void
    {
        $this->assertTrue($this->helper->callInputBool(['key' => 1], 'key'));
    }

    public function testInputBoolAcceptsInt0(): void
    {
        $this->assertFalse($this->helper->callInputBool(['key' => 0], 'key'));
    }

    public function testInputBoolAcceptsString1(): void
    {
        $this->assertTrue($this->helper->callInputBool(['key' => '1'], 'key'));
    }

    public function testInputBoolAcceptsString0(): void
    {
        $this->assertFalse($this->helper->callInputBool(['key' => '0'], 'key'));
    }

    public function testInputBoolAcceptsStringTrue(): void
    {
        $this->assertTrue($this->helper->callInputBool(['key' => 'true'], 'key'));
    }

    public function testInputBoolAcceptsStringFalse(): void
    {
        $this->assertFalse($this->helper->callInputBool(['key' => 'false'], 'key'));
    }

    public function testInputBoolReturnsNullForInvalidType(): void
    {
        $this->assertNull($this->helper->callInputBool(['key' => 'yes'], 'key', false));
    }

    public function testInputBoolRejectsArray(): void
    {
        $this->assertNull($this->helper->callInputBool(['key' => []], 'key', false));
    }

    // =========================================================================
    // inputIntFromBool (for disabled field)
    // =========================================================================

    public function testInputIntFromBoolAcceptsBoolTrue(): void
    {
        $this->assertSame(1, $this->helper->callInputIntFromBool(['key' => true], 'key'));
    }

    public function testInputIntFromBoolAcceptsBoolFalse(): void
    {
        $this->assertSame(0, $this->helper->callInputIntFromBool(['key' => false], 'key'));
    }

    public function testInputIntFromBoolAcceptsInt1(): void
    {
        $this->assertSame(1, $this->helper->callInputIntFromBool(['key' => 1], 'key'));
    }

    public function testInputIntFromBoolAcceptsInt0(): void
    {
        $this->assertSame(0, $this->helper->callInputIntFromBool(['key' => 0], 'key'));
    }

    public function testInputIntFromBoolAcceptsNumericString(): void
    {
        $this->assertSame(1, $this->helper->callInputIntFromBool(['key' => '1'], 'key'));
    }

    public function testInputIntFromBoolAcceptsStringTrue(): void
    {
        $this->assertSame(1, $this->helper->callInputIntFromBool(['key' => 'true'], 'key'));
    }

    public function testInputIntFromBoolAcceptsStringFalse(): void
    {
        $this->assertSame(0, $this->helper->callInputIntFromBool(['key' => 'false'], 'key'));
    }

    public function testInputIntFromBoolReturnsDefaultForMissingKey(): void
    {
        $this->assertSame(0, $this->helper->callInputIntFromBool([], 'key'));
    }

    public function testInputIntFromBoolReturnsNullForInvalidType(): void
    {
        $this->assertNull($this->helper->callInputIntFromBool(['key' => 'yes'], 'key'));
    }

    public function testInputIntFromBoolRejectsArray(): void
    {
        $this->assertNull($this->helper->callInputIntFromBool(['key' => [1]], 'key'));
    }

    // =========================================================================
    // Template extraction
    // =========================================================================

    public function testTemplateBooleanTrueRejected(): void
    {
        $this->assertSame('none', $this->helper->callInputTemplate(['template' => true]));
    }

    public function testTemplateArrayRejected(): void
    {
        $this->assertSame('none', $this->helper->callInputTemplate(['template' => [1, 2]]));
    }

    public function testTemplateValidNumericString(): void
    {
        $this->assertSame('5', $this->helper->callInputTemplate(['template' => '5']));
    }

    public function testTemplateNoneString(): void
    {
        $this->assertSame('none', $this->helper->callInputTemplate(['template' => 'none']));
    }

    public function testTemplateIntegerPreserved(): void
    {
        $this->assertSame('1', $this->helper->callInputTemplate(['template' => 1]));
    }

    public function testTemplateIntegerZero(): void
    {
        $this->assertSame('0', $this->helper->callInputTemplate(['template' => 0]));
    }

    public function testTemplateMissingDefaultsToNone(): void
    {
        $this->assertSame('none', $this->helper->callInputTemplate([]));
    }

    // =========================================================================
    // Regression tests: invalid input must return null, not default
    // =========================================================================

    public function testInvalidTtlReturnsNull(): void
    {
        $this->assertNull($this->helper->callInputInt(['ttl' => 'abc'], 'ttl', 3600));
    }

    public function testInvalidPriorityReturnsNull(): void
    {
        $this->assertNull($this->helper->callInputInt(['priority' => []], 'priority', 0));
    }

    public function testInvalidDisabledReturnsNull(): void
    {
        $this->assertNull($this->helper->callInputIntFromBool(['disabled' => 'yes'], 'disabled', 0));
    }

    public function testInvalidOwnerReturnsNull(): void
    {
        $this->assertNull($this->helper->callInputInt(['owner_user_id' => 'admin'], 'owner_user_id', 1));
    }
}
