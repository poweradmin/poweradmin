<?php

namespace Poweradmin\Tests\Unit\Application\Controller\Dnssec;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Dnssec\DnssecKeyController;
use Poweradmin\Application\Controller\Dnssec\DnssecToggleKeyController;

class DnssecToggleKeyControllerTest extends TestCase
{
    public function testControllerSharesTheDnssecKeyGate(): void
    {
        $this->assertTrue(is_subclass_of(DnssecToggleKeyController::class, DnssecKeyController::class));
    }
}
