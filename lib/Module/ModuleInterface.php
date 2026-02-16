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

/**
 * Interface for Poweradmin modules.
 *
 * Modules are self-contained features that can be enabled/disabled via configuration.
 * Each module can provide routes, navigation items, capabilities, and templates.
 */
interface ModuleInterface
{
    /**
     * Get unique module identifier (e.g., 'csv_export', 'zone_import_export')
     */
    public function getName(): string;

    /**
     * Get human-readable display name
     */
    public function getDisplayName(): string;

    /**
     * Get short description of what the module does
     */
    public function getDescription(): string;

    /**
     * Get route definitions for this module.
     *
     * Each route is an associative array with keys:
     * - 'name' (string): Route name (e.g., 'module_csv_export')
     * - 'path' (string): URL path (e.g., '/zones/{id}/export/csv')
     * - 'controller' (string): Fully qualified controller class::method
     * - 'methods' (array): HTTP methods (e.g., ['GET'])
     * - 'requirements' (array, optional): Parameter regex constraints
     *
     * @return array<array<string, mixed>>
     */
    public function getRoutes(): array;

    /**
     * Get navigation items to add to the Tools dropdown.
     *
     * Each item is an associative array with keys:
     * - 'label' (string): Display text
     * - 'url' (string): URL path
     * - 'icon' (string): Bootstrap Icons class name (without 'bi-' prefix)
     * - 'page_id' (string): Page identifier for active state detection
     * - 'permission' (string, optional): Required permission to show this item
     *
     * @return array<array<string, string>>
     */
    public function getNavItems(): array;

    /**
     * Get list of capabilities this module provides.
     *
     * Known capabilities:
     * - 'zone_export': Module provides zone export formats
     * - 'zone_import': Module provides zone import functionality
     *
     * @return string[]
     */
    public function getCapabilities(): array;

    /**
     * Get data for a specific capability.
     *
     * For 'zone_export', returns an array of export format definitions:
     * - 'label' (string): Display text (e.g., 'CSV', 'Zone File')
     * - 'url_pattern' (string): URL with {id} placeholder (e.g., '/zones/{id}/export/csv')
     * - 'icon' (string): Bootstrap Icons class name (without 'bi-' prefix)
     *
     * @param string $capability The capability identifier
     * @return array<array<string, string>>
     */
    public function getCapabilityData(string $capability): array;

    /**
     * Get the absolute path to the module's template directory.
     *
     * Return an empty string if the module has no templates.
     */
    public function getTemplatePath(): string;

    /**
     * Get the absolute path to the module's locale directory.
     *
     * The directory should contain subdirectories per language (e.g., en_EN/messages.po).
     * Return an empty string if the module has no translations.
     */
    public function getLocalePath(): string;
}
