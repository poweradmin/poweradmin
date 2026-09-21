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

use Poweradmin\Application\Module\ModuleManifest;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Module\ModuleInterface;

/**
 * Loads the modules listed in the manifest and answers which are enabled for the current user.
 *
 * Provides aggregated routes, navigation, and capabilities of the enabled modules.
 */
class ModuleRegistry
{
    private ConfigurationInterface $config;

    /** @var array<string, class-string<ModuleInterface>> */
    private array $moduleClasses;

    /** @var array<string, ModuleInterface> */
    private array $enabledModules = [];

    private bool $loaded = false;

    /**
     * @param array<string, class-string<ModuleInterface>> $moduleClasses Module classes by config name; the manifest by default
     */
    public function __construct(ConfigurationInterface $config, array $moduleClasses = ModuleManifest::MODULES)
    {
        $this->config = $config;
        $this->moduleClasses = $moduleClasses;
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

            $enabled = $this->config->get('modules', "$name.enabled", null);

            // Legacy config fallback: check standalone config section for modules
            // that were previously configured outside the modules section
            if ($enabled === null) {
                $enabled = $this->config->get($name, 'enabled', null);
            }

            // Additional legacy fallback for email_previews (was misc.email_previews_enabled)
            // Only applies when no module key exists at all (both checks returned null)
            if ($enabled === null && $name === 'email_previews') {
                $enabled = $this->config->get('misc', 'email_previews_enabled', false);
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
            if (!$isAdmin && $this->isRestrictedToAdmin($name)) {
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

            if (!$isAdmin && $this->isRestrictedToAdmin($name)) {
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

    /**
     * modules.<name>.restrict_to_admin, with the legacy <name>.restrict_to_admin key as fallback.
     */
    private function isRestrictedToAdmin(string $name): bool
    {
        return (bool)($this->config->get('modules', "$name.restrict_to_admin", null)
            ?? $this->config->get($name, 'restrict_to_admin', false));
    }
}
