<?php

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
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

    public function testControllerExtendsBaseController(): void
    {
        $reflection = new \ReflectionClass(DnssecToggleKeyController::class);
        $this->assertEquals('Poweradmin\BaseController', $reflection->getParentClass()->getName());
    }
}
