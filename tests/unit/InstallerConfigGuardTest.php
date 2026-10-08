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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoweradminInstall\InstallationSteps;
use PoweradminInstall\Installer;

class InstallerConfigGuardTest extends TestCase
{
    private string $configFile;
    private string|false $savedConfigPath;

    protected function setUp(): void
    {
        if (!class_exists('PoweradminInstall\Installer')) {
            $this->markTestSkipped('Install folder not present - Installer not available');
        }
        $this->configFile = sys_get_temp_dir() . '/pa-install-guard-' . uniqid() . '.php';
        $this->savedConfigPath = getenv('PA_CONFIG_PATH');
    }

    protected function tearDown(): void
    {
        @unlink($this->configFile);
        putenv($this->savedConfigPath === false ? 'PA_CONFIG_PATH' : 'PA_CONFIG_PATH=' . $this->savedConfigPath);
    }

    public static function stepsBeforeCompletion(): array
    {
        return array_map(
            fn (int $step) => [$step],
            // Literal bounds: the provider runs before setUp() can skip a missing install/ folder
            range(1, 7)
        );
    }

    #[DataProvider('stepsBeforeCompletion')]
    public function testRefusesEveryStepButCompletionOnceConfigExists(int $step): void
    {
        touch($this->configFile);

        $this->assertTrue(Installer::refusesStep($step, [$this->configFile]));
    }

    public function testShowsCompletionPageOnceConfigExists(): void
    {
        touch($this->configFile);

        $this->assertFalse(Installer::refusesStep(InstallationSteps::STEP_INSTALLATION_COMPLETE, [$this->configFile]));
    }

    #[DataProvider('stepsBeforeCompletion')]
    public function testAllowsEveryStepWithoutConfig(int $step): void
    {
        $this->assertFalse(Installer::refusesStep($step, [$this->configFile]));
    }

    public function testConfigurationFilesIncludeCustomConfigPath(): void
    {
        putenv('PA_CONFIG_PATH=' . $this->configFile);

        $files = Installer::configurationFiles();

        $this->assertContains($this->configFile, $files);
        $this->assertStringEndsWith('/config/settings.php', $files[0]);
    }

    public function testCustomConfigPathAloneRefusesInstall(): void
    {
        putenv('PA_CONFIG_PATH=' . $this->configFile);
        touch($this->configFile);

        $this->assertTrue(Installer::refusesStep(
            InstallationSteps::STEP_CONFIGURING_DATABASE,
            Installer::configurationFiles()
        ));
    }

    public function testConfigurationFilesIgnoreEmptyCustomConfigPath(): void
    {
        putenv('PA_CONFIG_PATH=');

        $this->assertCount(1, Installer::configurationFiles());
    }
}
