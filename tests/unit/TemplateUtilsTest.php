<?php

namespace unit;

use PHPUnit\Framework\TestCase;

class TemplateUtilsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\PoweradminInstall\StepValidator::class)) {
            $this->markTestSkipped('Install folder is not present - StepValidator class not available');
        }
    }

    public function testCanGetStepFromValidInput(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(3, $stepValidator->getCurrentStep(3));
    }

    public function testCanReturnDefaultStepWhenInputIsEmpty(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(null));
    }

    public function testCanHandleNonNumericStep(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('non-numeric'));
    }

    public function testGetCurrentStepWithVeryLargeNumber(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('999999999999999999999999'));
    }

    public function testGetCurrentStepWithNegativeNumber(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(-5));
    }

    public function testGetCurrentStepWithZero(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(0));
    }

    public function testGetCurrentStepWithFloat(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(3.5));
    }

    public function testGetCurrentStepWithStringNumber(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(5, $stepValidator->getCurrentStep('5'));
    }

    public function testGetCurrentStepWithNonAsciiNumbers(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('٣'));
    }

    public function testGetCurrentStepWithInjection(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('<script>alert("test")</script>'));
    }

    public function testGetCurrentStepWithArray(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(['1', '2']));
    }

    public function testGetCurrentStepWithLeadingWhitespace(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(' 42'));
    }

    public function testGetCurrentStepWithTrailingWhitespace(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('42 '));
    }

    public function testGetCurrentStepWithInternalWhitespace(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('4 2'));
    }

    public function testGetCurrentStepWithEmptyString(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(''));
    }

    public function testGetCurrentStepWithSpecialCharacters(): void
    {
        $stepValidator = new \PoweradminInstall\StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('4@2'));
    }
}
