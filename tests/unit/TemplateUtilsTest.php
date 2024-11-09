<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use PoweradminInstall\StepValidator;

class TemplateUtilsTest extends TestCase
{
    public function testCanGetStepFromValidInput(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(3, $stepValidator->getCurrentStep(3));
    }

    public function testCanReturnDefaultStepWhenInputIsEmpty(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(null));
    }

    public function testCanHandleNonNumericStep(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('non-numeric'));
    }

    public function testGetCurrentStepWithVeryLargeNumber(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('999999999999999999999999'));
    }

    public function testGetCurrentStepWithNegativeNumber(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(-5));
    }

    public function testGetCurrentStepWithZero(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(0));
    }

    public function testGetCurrentStepWithFloat(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(3.5));
    }

    public function testGetCurrentStepWithStringNumber(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(5, $stepValidator->getCurrentStep('5'));
    }

    public function testGetCurrentStepWithNonAsciiNumbers(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('٣'));
    }

    public function testGetCurrentStepWithInjection(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('<script>alert("test")</script>'));
    }

    public function testGetCurrentStepWithArray(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(['1', '2']));
    }

    public function testGetCurrentStepWithLeadingWhitespace(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(' 42'));
    }

    public function testGetCurrentStepWithTrailingWhitespace(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('42 '));
    }

    public function testGetCurrentStepWithInternalWhitespace(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('4 2'));
    }

    public function testGetCurrentStepWithEmptyString(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep(''));
    }

    public function testGetCurrentStepWithSpecialCharacters(): void
    {
        $stepValidator = new StepValidator();
        $this->assertEquals(1, $stepValidator->getCurrentStep('4@2'));
    }
}
