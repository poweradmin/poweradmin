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

namespace Poweradmin\Module;

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Module Registry
 *
 * Central registry for all available modules. Manages module lifecycle,
 * availability, and provides aggregated routes, navigation, and capabilities.
 */
class ModuleRegistry
{
    private ConfigurationManager $config;

    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    /** @var array<string, ModuleInterface> */
    private array $enabledModules = [];

    private bool $loaded = false;

    /** @var array<string, class-string<ModuleInterface>> */
    private array $moduleClasses = [
        'csv_export' => \Poweradmin\Module\CsvExport\CsvExportModule::class,
        'zone_import_export' => \Poweradmin\Module\ZoneImportExport\ZoneImportExportModule::class,
        'whois' => \Poweradmin\Module\Whois\WhoisModule::class,
        'rdap' => \Poweradmin\Module\Rdap\RdapModule::class,
    ];

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
    }

    /**
     * Load and initialize all registered modules.
     * Checks configuration to determine which modules are enabled.
     */
    public function loadModules(): void
    {
        if ($this->loaded) {
            return;
        }

        foreach ($this->moduleClasses as $name => $className) {
            if (!class_exists($className)) {
                continue;
            }

            $module = new $className();

            if (!$module instanceof ModuleInterface) {
                continue;
            }

            $this->modules[$name] = $module;

            $enabled = $this->config->get('modules', "$name.enabled", false);

            // Legacy config fallback: check standalone config section for modules
            // that were previously configured outside the modules section
            if (!$enabled) {
                $enabled = $this->config->get($name, 'enabled', false);
            }

            if ($enabled) {
                $this->enabledModules[$name] = $module;
            }
        }

        $this->loaded = true;
    }

    /**
     * @return array<string, ModuleInterface>
     */
    public function getEnabledModules(): array
    {
        return $this->enabledModules;
    }

    /**
     * @return array<string, ModuleInterface>
     */
    public function getAllModules(): array
    {
        return $this->modules;
    }

    /**
     * Get aggregated route definitions from all enabled modules.
     *
     * @return array<array<string, mixed>>
     */
    public function getRoutes(): array
    {
        $routes = [];
        foreach ($this->enabledModules as $module) {
            foreach ($module->getRoutes() as $route) {
                $routes[] = $route;
            }
        }
        return $routes;
    }

    /**
     * Get aggregated navigation items from all enabled modules.
     *
     * @param bool $isAdmin Whether the current user is an administrator
     * @return array<array<string, string>>
     */
    public function getNavItems(bool $isAdmin = false): array
    {
        $items = [];
        foreach ($this->enabledModules as $name => $module) {
            $restrictToAdmin = $this->config->get('modules', "$name.restrict_to_admin", false)
                || $this->config->get($name, 'restrict_to_admin', false);
            if ($restrictToAdmin && !$isAdmin) {
                continue;
            }

            foreach ($module->getNavItems() as $item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * Get aggregated capability data from all enabled modules.
     *
     * @param string $capability The capability identifier (e.g., 'zone_export')
     * @param array<string, mixed> $context Context for placeholder resolution (e.g., ['zone_id' => 123])
     * @param bool $isAdmin Whether the current user is an administrator
     * @return array<array<string, string>>
     */
    public function getCapabilityData(string $capability, array $context = [], bool $isAdmin = false): array
    {
        $data = [];
        foreach ($this->enabledModules as $name => $module) {
            if (!in_array($capability, $module->getCapabilities(), true)) {
                continue;
            }

            // Skip module capabilities when restrict_to_admin is enabled and user is not admin
            $restrictToAdmin = $this->config->get('modules', "$name.restrict_to_admin", false)
                || $this->config->get($name, 'restrict_to_admin', false);
            if ($restrictToAdmin && !$isAdmin) {
                continue;
            }

            foreach ($module->getCapabilityData($capability) as $item) {
                if (isset($item['url_pattern']) && isset($context['zone_id'])) {
                    $item['url'] = str_replace('{id}', (string)$context['zone_id'], $item['url_pattern']);
                }
                $data[] = $item;
            }
        }
        return $data;
    }
}
