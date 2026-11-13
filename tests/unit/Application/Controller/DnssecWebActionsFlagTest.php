<?php

namespace Poweradmin\Tests\Unit\Application\Controller;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\DnssecAddKeyController;
use Poweradmin\Application\Controller\DnssecDeleteKeyController;
use Poweradmin\Application\Controller\DnssecEditKeyController;
use Poweradmin\Application\Controller\DnssecKeyExportController;
use Poweradmin\Application\Controller\DnssecKeyImportController;
use Poweradmin\Application\Controller\DnssecToggleKeyController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use RuntimeException;

/**
 * With dnssec.enabled off the web key actions refuse before reading the request or the zone.
 */
class DnssecWebActionsFlagTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function keyControllers(): array
    {
        return [
            'add key' => [DnssecAddKeyController::class],
            'edit key' => [DnssecEditKeyController::class],
            'delete key' => [DnssecDeleteKeyController::class],
            'toggle key' => [DnssecToggleKeyController::class],
            'import key' => [DnssecKeyImportController::class],
            'export key' => [DnssecKeyExportController::class],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('keyControllers')]
    public function testTheActionIsRefusedWhileDnssecIsSwitchedOff(string $class): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(static fn(string $group, string $key, mixed $default = null): mixed => $default);

        $controller = $this->controller($class, $config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DNSSEC is not enabled on the server.');
        $controller->run();
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('keyControllers')]
    public function testTheActionGoesOnToItsOwnChecksWhileDnssecIsSwitchedOn(string $class): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            static fn(string $group, string $key, mixed $default = null): mixed => $group === 'dnssec' && $key === 'enabled' ? true : $default
        );

        $controller = $this->controller($class, $config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^(?!DNSSEC is not enabled on the server)/');
        $controller->run();
    }

    private function controller(string $class, ConfigurationManager $config): object
    {
        $db = $this->createMock(PDO::class);

        return match ($class) {
            DnssecAddKeyController::class => new class ([], $db, $config) extends DnssecAddKeyController {
                use ControllerTestStubs;
            },
            DnssecEditKeyController::class => new class ([], $db, $config) extends DnssecEditKeyController {
                use ControllerTestStubs;
            },
            DnssecDeleteKeyController::class => new class ([], $db, $config) extends DnssecDeleteKeyController {
                use ControllerTestStubs;
            },
            DnssecToggleKeyController::class => new class ([], $db, $config) extends DnssecToggleKeyController {
                use ControllerTestStubs;
            },
            DnssecKeyImportController::class => new class ([], $db, $config) extends DnssecKeyImportController {
                use ControllerTestStubs;
            },
            default => new class ([], $db, $config) extends DnssecKeyExportController {
                use ControllerTestStubs;
            },
        };
    }
}
