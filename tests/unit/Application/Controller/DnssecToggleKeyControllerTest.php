<?php

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\DnssecKeyController;
use Poweradmin\Application\Controller\DnssecToggleKeyController;

class DnssecToggleKeyControllerTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(DnssecToggleKeyController::class));
    }

    public function testControllerHasRunMethod(): void
    {
        $this->assertTrue(method_exists(DnssecToggleKeyController::class, 'run'));
    }

    public function testControllerSharesTheDnssecKeyGate(): void
    {
        $this->assertTrue(is_subclass_of(DnssecToggleKeyController::class, DnssecKeyController::class));
    }
}
