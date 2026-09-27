<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Service\NullDnssecProvider;

class NullDnssecProviderTest extends TestCase
{
    public function testWithoutThePowerDnsApiKeysCannotBeAskedOrCreated(): void
    {
        $provider = new NullDnssecProvider();

        $this->assertNull($provider->fetchZoneKeys('example.com'));
        $this->assertNull($provider->createZoneKey('example.com', 'csk', 256, 'ecdsa256', false));
    }
}
