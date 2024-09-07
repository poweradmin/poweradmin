<?php

use PHPUnit\Framework\TestCase;
use PoweradminInstall\StepValidator;

class TemplateUtilsTest extends TestCase
{

    public function testCanGetStepFromPostRequest(): void
    {
        $postData = ['step' => 3];
        $stepValidator = new StepValidator();
        $this->assertEquals(3, $stepValidator->getCurrentStep($postData));
        
        unset($_POST['step']);
    }

    public function testCanReturnDefaultStepWhenNotInPostRequest(): void
    {
        $postData = [];
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep($postData));
    }

    public function testCanHandleNonNumericStepInPostRequest(): void
    {
        $postData = ['step' => 'non-numeric'];
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep($postData));

        unset($_POST['step']);
    }

    public function testGetCurrentStepWithVeryLargeNumber()
    {
        $postData = ['step' => '999999999999999999999999'];
        $stepValidator = new StepValidator();
        $result = $stepValidator->getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithNegativeNumber()
    {
        $postData = ['step' => '-5'];
        $stepValidator = new StepValidator();
        $result = $stepValidator->getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithZero()
    {
        $postData = ['step' => '0'];
        $stepValidator = new StepValidator();
        $result = $stepValidator->getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithFloat()
    {
        $postData = ['step' => '3.5'];
        $stepValidator = new StepValidator();
        $result = $stepValidator->getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithStringNumber()
    {
        $postData = ['step' => '5'];
        $stepValidator = new StepValidator();
        $result = $stepValidator->getCurrentStep($postData);
        $this->assertEquals(5, $result);
    }

    public function testGetCurrentStepWithNonAsciiNumbers()
    {
        $postData = ['step' => '٣'];
        $stepValidator = new StepValidator();
        $result = $stepValidator->getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithInjection()
    {
        $postData = ['step' => '<script>alert("test")</script>'];
        $stepValidator = new StepValidator();
        $result = $stepValidator->getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithArray()
    {
        $postData = ['step' => ['1', '2']];
        $stepValidator = new StepValidator();
        $result = $stepValidator->getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }
}