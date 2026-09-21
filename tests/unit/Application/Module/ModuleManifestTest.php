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

namespace Poweradmin\Tests\Unit\Application\Module;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Module\ModuleManifest;
use Poweradmin\Application\Module\ModuleRegistry;
use Poweradmin\Domain\Module\ModuleInterface;
use ReflectionClass;
use TestHelpers\FakeConfiguration;

class ModuleManifestTest extends TestCase
{
    public function testListsExactlyTheBundledModules(): void
    {
        $this->assertSame(
            ['csv_export', 'zone_import_export', 'whois', 'rdap', 'dns_wizards', 'email_previews', 'secondary_zone_import'],
            array_keys(ModuleManifest::MODULES)
        );
    }

    public function testEveryEntryIsAModuleReportingItsOwnKey(): void
    {
        $registry = $this->allEnabled();

        $this->assertSame(array_keys(ModuleManifest::MODULES), array_keys($registry->getEnabledModules()));
        foreach ($registry->getEnabledModules() as $name => $module) {
            $this->assertInstanceOf(ModuleInterface::class, $module, $name);
            $this->assertSame($name, $module->getName(), $name);
        }
    }

    public function testEveryDeclaredCapabilityIsANamedConstant(): void
    {
        $known = [];
        foreach ((new ReflectionClass(ModuleInterface::class))->getConstants() as $constant => $value) {
            if (str_starts_with($constant, 'CAP_')) {
                $known[] = $value;
            }
        }
        $this->assertSame(['zone_export', 'zone_import', 'whois_lookup', 'rdap_lookup', 'dns_wizard'], $known);

        $declared = [];
        foreach ($this->allEnabled()->getEnabledModules() as $name => $module) {
            foreach ($module->getCapabilities() as $capability) {
                $this->assertContains($capability, $known, "$name declares an unnamed capability");
                $declared[] = $capability;
            }
        }
        $this->assertSame([], array_diff($known, $declared), 'a named capability no bundled module provides');
    }

    private function allEnabled(): ModuleRegistry
    {
        $enabled = [];
        foreach (array_keys(ModuleManifest::MODULES) as $name) {
            $enabled["$name.enabled"] = true;
        }
        $registry = new ModuleRegistry(new FakeConfiguration(['modules' => $enabled]));
        $registry->loadModules();

        return $registry;
    }
}
