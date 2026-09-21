<?php

namespace Poweradmin\Tests\Unit\Application\Module;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Module\ModuleServices;
use Poweradmin\Application\Service\ControllerServiceFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;

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
