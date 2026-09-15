<?php

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\DnssecKeyController;
use Poweradmin\Application\Controller\DnssecToggleKeyController;

class DnssecToggleKeyControllerTest extends TestCase
{
    public function testControllerSharesTheDnssecKeyGate(): void
    {
        $this->assertTrue(is_subclass_of(DnssecToggleKeyController::class, DnssecKeyController::class));
    }
}
