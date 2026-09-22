<?php

namespace Poweradmin\Tests\Unit\Application\Module;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Module\ModuleServices;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Port\EmailTemplateRendererInterface;
use Poweradmin\Domain\Port\ProxyContextInterface;
use Poweradmin\Infrastructure\Network\EnvironmentProxyContext;
use Poweradmin\Infrastructure\Session\FormStateService;
use Poweradmin\Infrastructure\Utility\CsvFormulaEscaper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * Pins the module SDK surface: every service a module obtains through
 * moduleServices() must be declared on the interface, and the interface
 * must carry only what a module actually calls.
 */
class ModuleServicesTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../../lib/Module';

    public function testControllerServiceFactoryImplementsTheContract(): void
    {
        $this->assertTrue(is_subclass_of(ControllerServiceFactory::class, ModuleServices::class));
    }

    public function testEveryModuleServicesCallResolvesToAnInterfaceMethod(): void
    {
        $declared = $this->interfaceMethods();
        $missing = [];

        foreach ($this->moduleServicesCalls() as $file => $methods) {
            foreach ($methods as $method) {
                if (!in_array($method, $declared, true)) {
                    $missing[] = "$file: moduleServices()->$method()";
                }
            }
        }

        $this->assertSame([], $missing, 'lib/Module calls services outside the ModuleServices contract');
    }

    public function testEveryInterfaceMethodIsCalledByAModule(): void
    {
        $called = array_unique(array_merge([], ...array_values($this->moduleServicesCalls())));
        $unused = array_values(array_diff($this->interfaceMethods(), $called));

        $this->assertSame([], $unused, 'ModuleServices declares methods no module uses');
    }

    public function testModulesNeverReachTheFullFactory(): void
    {
        $offenders = [];

        foreach ($this->moduleFiles() as $file) {
            if (preg_match('/\$this->services\(\)/', file_get_contents($file)) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'lib/Module must use moduleServices(), not services()');
    }

    public function testInterfaceMethodsHaveExplicitReturnTypes(): void
    {
        foreach ((new ReflectionClass(ModuleServices::class))->getMethods() as $method) {
            $type = $method->getReturnType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type, $method->getName());
            $this->assertFalse($type->isBuiltin(), $method->getName() . ' must return a service type');
        }
    }

    /**
     * A module names only the SDK: BaseController, ModuleServices, the value types
     * listed in phpstan.neon and lib/Domain. Everything else comes through moduleServices().
     */
    public function testModulesImportNoCoreServiceOrInfrastructureClass(): void
    {
        $allowed = [
            'Poweradmin\\Application\\Service\\Record\\RecordAddResult',
            'Poweradmin\\Application\\Service\\Record\\RecordAddAccess',
            'Poweradmin\\Application\\Service\\Zone\\ChangeRequestMessages',
            'Poweradmin\\Application\\Service\\Zone\\ZoneCreateRequest',
            'Poweradmin\\Application\\Service\\Zone\\ZoneCreateFormMessages',
        ];
        $offenders = [];

        foreach ($this->moduleFiles() as $file) {
            preg_match_all('/^use (Poweradmin\\\\(?:Application\\\\Service|Infrastructure)\\\\[A-Za-z\\\\]+);/m', file_get_contents($file), $matches);
            foreach (array_diff($matches[1], $allowed) as $import) {
                $offenders[] = substr($file, strlen(self::MODULE_DIR) + 1) . ': ' . $import;
            }
        }

        $this->assertSame([], $offenders, 'lib/Module imports a core service or Infrastructure class');
    }

    /**
     * The accessors added for the module edge are typed by a port or a dependency-free
     * helper, never by a core service a module would have to import.
     */
    public function testModuleEdgeAccessorsReturnTheDeclaredTypes(): void
    {
        $expected = [
            'csvFormulaEscaper' => CsvFormulaEscaper::class,
            'emailTemplateService' => EmailTemplateRendererInterface::class,
            'formStateService' => FormStateService::class,
            'proxyContext' => ProxyContextInterface::class,
        ];

        foreach ($expected as $method => $type) {
            $declared = (new ReflectionMethod(ModuleServices::class, $method))->getReturnType();
            $this->assertInstanceOf(ReflectionNamedType::class, $declared);
            $this->assertSame($type, $declared->getName(), $method);
        }
    }

    public function testFactoryMemoizesTheModuleEdgeHelpers(): void
    {
        $factory = new ControllerServiceFactory($this->createMock(PDO::class), new FakeConfiguration(), new NullLogger(), $this->createMock(ActorInterface::class), new ArraySession());

        $this->assertSame($factory->csvFormulaEscaper(), $factory->csvFormulaEscaper());
        $this->assertSame($factory->formStateService(), $factory->formStateService());
        $this->assertSame($factory->proxyContext(), $factory->proxyContext());
        $this->assertInstanceOf(EnvironmentProxyContext::class, $factory->proxyContext());
        $this->assertInstanceOf(EmailTemplateRendererInterface::class, $factory->emailTemplateService());
    }

    /** @return list<string> */
    private function interfaceMethods(): array
    {
        $names = array_map(
            static fn($method) => $method->getName(),
            (new ReflectionClass(ModuleServices::class))->getMethods()
        );
        sort($names);

        return $names;
    }

    /** @return array<string, list<string>> file => called method names */
    private function moduleServicesCalls(): array
    {
        $calls = [];

        foreach ($this->moduleFiles() as $file) {
            if (preg_match_all('/moduleServices\(\)->([A-Za-z_]+)\(/', file_get_contents($file), $matches) > 0) {
                $calls[substr($file, strlen(self::MODULE_DIR) + 1)] = array_values(array_unique($matches[1]));
            }
        }

        $this->assertNotSame([], $calls, 'expected at least one moduleServices() call under lib/Module');

        return $calls;
    }

    /** @return list<string> */
    private function moduleFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::MODULE_DIR));

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
