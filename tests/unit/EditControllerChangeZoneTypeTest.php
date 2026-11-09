<?php

namespace unit;

use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditController;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;
use ReflectionClass;

/**
 * Converting a zone is equivalent to creating one of the target type, so
 * zone-meta edit alone must not be enough.
 *
 * UserManager::verify_permission() caches the permission set in a static,
 * so every case runs in its own process.
 */
class EditControllerChangeZoneTypeTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'SLAVE needs zone_slave_add' => ['SLAVE', 'zone_master_add', false],
            'MASTER needs zone_master_add' => ['MASTER', 'zone_slave_add', false],
            'NATIVE needs zone_master_add' => ['NATIVE', 'zone_slave_add', false],
            'SLAVE allowed' => ['SLAVE', 'zone_slave_add', true],
            'MASTER allowed' => ['MASTER', 'zone_master_add', true],
            'NATIVE allowed' => ['NATIVE', 'zone_master_add', true],
            'superuser allowed' => ['SLAVE', 'user_is_ueberuser', true],
        ];
    }

    #[DataProvider('cases')]
    #[RunInSeparateProcess]
    public function testConversionRequiresAddPermissionOfTargetType(string $type, string $granted, bool $allowed): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchAll')->willReturn([$granted => []]);
        $db = $this->createMock(PDOLayer::class);
        $db->method('prepare')->willReturn($statement);
        $_SESSION = ['userid' => 7, 'messages' => []];

        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->expects($allowed ? $this->once() : $this->never())->method('change_zone_type');

        $controller = (new ReflectionClass(EditController::class))->newInstanceWithoutConstructor();
        (new ReflectionClass(BaseController::class))->getProperty('db')->setValue($controller, $db);

        $controller->changeZoneType($dnsRecord, $type, 42);

        /** @var array{messages?: array{edit?: list<array{type: string}>}} $session */
        $session = $GLOBALS['_SESSION'];
        $messages = $session['messages']['edit'] ?? [];
        $this->assertSame($allowed ? [] : ['error'], array_column($messages, 'type'));
    }
}
