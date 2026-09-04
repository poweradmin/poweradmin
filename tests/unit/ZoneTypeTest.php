<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneType;

#[CoversClass(ZoneType::class)]
class ZoneTypeTest extends TestCase
{
    #[Test]
    public function constantsMasterSlaveNative(): void
    {
        $this->assertSame('MASTER', ZoneType::MASTER);
        $this->assertSame('SLAVE', ZoneType::SLAVE);
        $this->assertSame('NATIVE', ZoneType::NATIVE);
    }

    #[Test]
    public function getTypesReturnsAllThree(): void
    {
        $types = ZoneType::getTypes();

        $this->assertCount(3, $types);
        $this->assertContains('MASTER', $types);
        $this->assertContains('SLAVE', $types);
        $this->assertContains('NATIVE', $types);
    }

    #[Test]
    public function isReadOnlyTrueForSlaveAndConsumer(): void
    {
        $this->assertTrue(ZoneType::isReadOnly('SLAVE'));
        $this->assertTrue(ZoneType::isReadOnly('CONSUMER'));
        $this->assertTrue(ZoneType::isReadOnly('consumer'));
    }

    #[Test]
    public function isReadOnlyFalseForEditableTypes(): void
    {
        $this->assertFalse(ZoneType::isReadOnly('MASTER'));
        $this->assertFalse(ZoneType::isReadOnly('NATIVE'));
        $this->assertFalse(ZoneType::isReadOnly('PRODUCER'));
        $this->assertFalse(ZoneType::isReadOnly(''));
        $this->assertFalse(ZoneType::isReadOnly(null));
    }

    #[Test]
    public function replicatesFromPrimaryTrueForSlaveAndConsumer(): void
    {
        $this->assertTrue(ZoneType::replicatesFromPrimary('SLAVE'));
        $this->assertTrue(ZoneType::replicatesFromPrimary('CONSUMER'));
        $this->assertTrue(ZoneType::replicatesFromPrimary('consumer'));
    }

    #[Test]
    public function replicatesFromPrimaryFalseForSelfHostedTypes(): void
    {
        $this->assertFalse(ZoneType::replicatesFromPrimary('MASTER'));
        $this->assertFalse(ZoneType::replicatesFromPrimary('NATIVE'));
        // A producer zone holds its own catalog; PowerDNS generates it, no transfer in.
        $this->assertFalse(ZoneType::replicatesFromPrimary('PRODUCER'));
        $this->assertFalse(ZoneType::replicatesFromPrimary(''));
        $this->assertFalse(ZoneType::replicatesFromPrimary(null));
    }

    #[Test]
    public function getCreatableTypesOmitsCatalogKindsWhenUnsupported(): void
    {
        $this->assertSame(['MASTER', 'NATIVE'], ZoneType::getCreatableTypes(false, true));
    }

    #[Test]
    public function getCreatableTypesOffersProducerWithoutSecondaryPermission(): void
    {
        $this->assertSame(['MASTER', 'NATIVE', 'PRODUCER'], ZoneType::getCreatableTypes(true, false));
    }

    #[Test]
    public function getCreatableTypesGatesConsumerOnSecondaryPermission(): void
    {
        $this->assertSame(['MASTER', 'NATIVE', 'PRODUCER', 'CONSUMER'], ZoneType::getCreatableTypes(true, true));
    }

    #[Test]
    public function getAllTypesCoversEveryKindPowerdnsAccepts(): void
    {
        $types = ZoneType::getAllTypes();

        $this->assertCount(5, $types);
        foreach (['MASTER', 'SLAVE', 'NATIVE', 'PRODUCER', 'CONSUMER'] as $type) {
            $this->assertContains($type, $types);
        }
    }

    #[Test]
    public function getReplicatingTypesMatchesThePredicate(): void
    {
        foreach (ZoneType::getReplicatingTypes() as $type) {
            $this->assertTrue(ZoneType::replicatesFromPrimary($type));
        }
    }

    #[Test]
    public function notifiesTrueForPrimaryAndProducer(): void
    {
        $this->assertTrue(ZoneType::notifies('MASTER'));
        $this->assertTrue(ZoneType::notifies('PRODUCER'));
        $this->assertTrue(ZoneType::notifies('master'));
    }

    #[Test]
    public function notifiesFalseForNonNotifyingTypes(): void
    {
        $this->assertFalse(ZoneType::notifies('SLAVE'));
        $this->assertFalse(ZoneType::notifies('NATIVE'));
        $this->assertFalse(ZoneType::notifies('CONSUMER'));
        $this->assertFalse(ZoneType::notifies(''));
        $this->assertFalse(ZoneType::notifies(null));
    }
}
