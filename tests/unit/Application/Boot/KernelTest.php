<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Application\Boot;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Boot\BootOptions;
use Poweradmin\Application\Boot\Kernel;
use RuntimeException;

class KernelTest extends TestCase
{
    private string|false $previousConfigPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConfigPath = getenv('PA_CONFIG_PATH');
    }

    protected function tearDown(): void
    {
        putenv($this->previousConfigPath === false ? 'PA_CONFIG_PATH' : 'PA_CONFIG_PATH=' . $this->previousConfigPath);
        parent::tearDown();
    }

    public function testConfigurationFileDefaultsToTheSettingsFileUnderTheWorkingDirectory(): void
    {
        putenv('PA_CONFIG_PATH');

        $this->assertSame('config/settings.php', Kernel::configurationFile());
    }

    public function testConfigurationFileHonoursPaConfigPath(): void
    {
        putenv('PA_CONFIG_PATH=/etc/poweradmin/settings.php');

        $this->assertSame('/etc/poweradmin/settings.php', Kernel::configurationFile());
    }

    public function testAnEmptyPaConfigPathFallsBackToTheDefault(): void
    {
        putenv('PA_CONFIG_PATH=');

        $this->assertSame('config/settings.php', Kernel::configurationFile());
    }

    public function testConnectOpensTheDatabaseFromMappedCredentials(): void
    {
        $db = Kernel::connect(['db_type' => 'sqlite', 'db_file' => ':memory:', 'db_user' => '', 'db_pass' => '']);

        $this->assertInstanceOf(PDO::class, $db);
        $this->assertSame('1', (string) $db->query('SELECT 1')->fetchColumn());
    }

    public function testConnectWrapsAFailureInAReadableMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database connection failed');

        Kernel::connect(['db_type' => 'sqlite', 'db_file' => '/nonexistent/poweradmin.sqlite', 'db_user' => '', 'db_pass' => '']);
    }

    /**
     * The web entry point starts a session (unless headless or probed) and refuses a
     * broken configuration; the scripts open the database up front and do neither;
     * the installer has a session for the wizard but no configuration yet.
     */
    public function testBootOptionsDescribeEachEntryPoint(): void
    {
        $this->assertTrue(BootOptions::Web->startsSession());
        $this->assertTrue(BootOptions::Web->skipsSessionWhenHeadless());
        $this->assertFalse(BootOptions::Web->connectsDatabase());
        $this->assertTrue(BootOptions::Web->guardsConfiguration());

        $this->assertFalse(BootOptions::Script->startsSession());
        $this->assertTrue(BootOptions::Script->connectsDatabase());
        $this->assertFalse(BootOptions::Script->guardsConfiguration());

        $this->assertTrue(BootOptions::Installer->startsSession());
        $this->assertFalse(BootOptions::Installer->skipsSessionWhenHeadless());
        $this->assertFalse(BootOptions::Installer->connectsDatabase());
        $this->assertFalse(BootOptions::Installer->guardsConfiguration());
    }

    /**
     * One configuration read per process: the kernel resolves the singleton, and
     * index.php once more only on its failure path, where boot never returned.
     */
    public function testOnlyTheKernelReachesForTheConfigurationSingleton(): void
    {
        $root = dirname(__DIR__, 4);
        $callers = [];

        foreach ($this->phpFilesUnder($root, ['lib', 'install', 'bin', 'index.php', 'dynamic_update.php']) as $file) {
            $count = substr_count((string) file_get_contents($file), 'ConfigurationManager::getInstance()');
            if ($count > 0) {
                $callers[substr($file, strlen($root) + 1)] = $count;
            }
        }
        ksort($callers);

        $this->assertSame([
            'index.php' => 1,
            'lib/Application/Boot/Kernel.php' => 1,
        ], $callers);
    }

    /**
     * @param list<string> $paths Files or directories relative to the root
     * @return list<string>
     */
    private function phpFilesUnder(string $root, array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $absolute = $root . '/' . $path;
            if (is_file($absolute)) {
                $files[] = $absolute;
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                $name = $entry->getPathname();
                if (str_ends_with($name, '.php') || str_ends_with($name, '/poweradmin')) {
                    $files[] = $name;
                }
            }
        }

        return $files;
    }
}
