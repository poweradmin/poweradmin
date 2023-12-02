<?php

use PHPUnit\Framework\TestCase;
use PoweradminInstall\TemplateUtils;

class TemplateUtilsTest extends TestCase
{

    public function testCanGetStepFromPostRequest(): void
    {
        $postData = ['step' => 3];
        $this->assertEquals(3, TemplateUtils::getCurrentStep($postData));
        
        unset($_POST['step']);
    }

    public function testCanReturnDefaultStepWhenNotInPostRequest(): void
    {
        $postData = [];
        $this->assertEquals(1, TemplateUtils::getCurrentStep($postData));
    }

    public function testCanHandleNonNumericStepInPostRequest(): void
    {
        $postData = ['step' => 'non-numeric'];
        $this->assertEquals(1, TemplateUtils::getCurrentStep($postData));

        unset($_POST['step']);
    }

    public function testGetCurrentStepWithVeryLargeNumber()
    {
        $postData = ['step' => '999999999999999999999999'];
        $result = TemplateUtils::getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithNegativeNumber()
    {
        $postData = ['step' => '-5'];
        $result = TemplateUtils::getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithZero()
    {
        $postData = ['step' => '0'];
        $result = TemplateUtils::getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithFloat()
    {
        $postData = ['step' => '3.5'];
        $result = TemplateUtils::getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithStringNumber()
    {
        $postData = ['step' => '5'];
        $result = TemplateUtils::getCurrentStep($postData);
        $this->assertEquals(5, $result);
    }

    public function testGetCurrentStepWithNonAsciiNumbers()
    {
        $postData = ['step' => '٣'];
        $result = TemplateUtils::getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithInjection()
    {
        $postData = ['step' => '<script>alert("test")</script>'];
        $result = TemplateUtils::getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }

    public function testGetCurrentStepWithArray()
    {
        $postData = ['step' => ['1', '2']];
        $result = TemplateUtils::getCurrentStep($postData);
        $this->assertEquals(1, $result);
    }
}