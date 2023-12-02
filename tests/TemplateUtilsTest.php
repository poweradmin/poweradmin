<?php

use PHPUnit\Framework\TestCase;
use PoweradminInstall\TemplateUtils;

class TemplateUtilsTest extends TestCase
{

    public function testCanGetStepFromPostRequest(): void
    {
        $_POST['step'] = 3;
        $this->assertEquals(3, TemplateUtils::getCurrentStep());
        
        unset($_POST['step']);
    }

    public function testCanReturnDefaultStepWhenNotInPostRequest(): void
    {
        $this->assertEquals(1, TemplateUtils::getCurrentStep());
    }

    public function testCanHandleNonNumericStepInPostRequest(): void
    {
        $_POST['step'] = 'non-numeric';
        $this->assertEquals(1, TemplateUtils::getCurrentStep());

        unset($_POST['step']);
    }

    public function testGetCurrentStepWithVeryLargeNumber()
    {
        $_POST['step'] = '999999999999999999999999'; // An extremely large number
        $result = TemplateUtils::getCurrentStep();
        $this->assertEquals(999999999999999999999999, $result);
    }

    public function testGetCurrentStepWithNegativeNumber()
    {
        $_POST['step'] = '-5'; // A negative number
        $result = TemplateUtils::getCurrentStep();
        $this->assertEquals(-5, $result);
    }

    public function testGetCurrentStepWithZero()
    {
        $_POST['step'] = '0';
        $result = TemplateUtils::getCurrentStep();
        $this->assertEquals(0, $result);
    }

    public function testGetCurrentStepWithFloat()
    {
        $_POST['step'] = '3.5';
        $result = TemplateUtils::getCurrentStep();
        $this->assertEquals(3.5, $result);
    }

    public function testGetCurrentStepWithStringNumber()
    {
        $_POST['step'] = '5';
        $result = TemplateUtils::getCurrentStep();
        $this->assertEquals(5, $result);
    }

    public function testGetCurrentStepWithNonAsciiNumbers()
    {
        $_POST['step'] = '٣';
        $result = TemplateUtils::getCurrentStep();
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithInjection()
    {
        $_POST['step'] = '<script>alert("test")</script>';
        $result = TemplateUtils::getCurrentStep();
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithArray()
    {
        $_POST['step'] = ['1', '2'];
        $result = TemplateUtils::getCurrentStep();
        $this->assertEquals(1, $result);
    }
}