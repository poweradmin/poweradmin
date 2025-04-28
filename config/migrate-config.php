<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

/**
 * This script migrates the old configuration format to the new one.
 * It reads from inc/config.inc.php and creates a new config/settings.php file.
 *
 * IMPORTANT NOTES:
 * - This script should only be run from the command line for security reasons.
 * - The old configuration format is deprecated as of version 4.0.0 and will be
 *   completely removed in the next major release.
 * - Run this migration script to preserve your settings in the new format.
 * - After migration, you can still use both configuration formats in version 4.0.0,
 *   but we recommend using only the new format for future compatibility.
 */

use Poweradmin\Infrastructure\Configuration\MigrationConfigurationManager;

// Ensure this script is only run from the command line
if (PHP_SAPI !== 'cli' && !defined('PHPUNIT_RUNNING')) {
    header('HTTP/1.1 403 Forbidden');
    echo "This script can only be executed from the command line.";
    exit(1);
}

// Use composer's autoloader instead of manual require statements
require_once __DIR__ . '/../vendor/autoload.php';

// Check if we have the necessary files
$legacyConfigFile = __DIR__ . '/../inc/config.inc.php';
$newConfigFile = __DIR__ . '/../config/settings.php';

if (!file_exists($legacyConfigFile)) {
    echo "Error: Legacy configuration file not found at: $legacyConfigFile\n";
    exit(1);
}

if (file_exists($newConfigFile)) {
    echo "Warning: New configuration file already exists at: $newConfigFile\n";
    echo "Do you want to overwrite it? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim($line) !== 'y') {
        echo "Migration canceled.\n";
        exit(0);
    }
    fclose($handle);
}

// Create instance of our migration manager
$migrationManager = new MigrationConfigurationManager();

// Use our custom migration method
$settings = $migrationManager->migrateWithCustomMapping($legacyConfigFile);

// Create the new configuration file
$configContent = "<?php\n";
$configContent .= "/**\n";
$configContent .= " * Poweradmin Settings Configuration File\n";
$configContent .= " * \n";
$configContent .= " * This file was automatically migrated from the old configuration format.\n";
$configContent .= " * Generated on: " . date('Y-m-d H:i:s') . "\n";
$configContent .= " * \n";
$configContent .= " * IMPORTANT: Review this file to ensure all settings were correctly migrated.\n";
$configContent .= " * For more information about configuration options, see settings.defaults.php\n";
$configContent .= " */\n\n";

// Format the settings array for better readability
$formattedSettings = var_export($settings, true);
// Replace ' => array (' with ' => ['
$formattedSettings = str_replace("array (", "[", $formattedSettings);
// Replace closing parentheses with brackets
$formattedSettings = str_replace(")", "]", $formattedSettings);
// Remove unnecessary NULL => from arrays (created by var_export)
$formattedSettings = preg_replace('/(\s+)\'[0-9]+\' => /', '$1', $formattedSettings);

$configContent .= "return " . $formattedSettings . ";\n";

// Write the file
if (!is_dir(dirname($newConfigFile))) {
    mkdir(dirname($newConfigFile), 0755, true);
}

file_put_contents($newConfigFile, $configContent);

echo "\n=====================================================================\n";
echo "✅ Configuration successfully migrated to: $newConfigFile\n";
echo "=====================================================================\n\n";
echo "IMPORTANT INFORMATION:\n";
echo "- Only settings that were defined in your old configuration file were migrated\n";
echo "- New features in version 4.0.0 will use defaults from settings.defaults.php\n";
echo "- This ensures a clean configuration file with only your customized settings\n\n";
echo "NEXT STEPS:\n";
echo "1. Review the new configuration file to ensure all settings were migrated correctly\n";
echo "2. Update any settings that need customization\n";
echo "3. Test your application to ensure everything works as expected\n\n";

// Optionally rename the old config file to indicate it's been migrated
echo "Do you want to rename the old configuration file to {$legacyConfigFile}.bak? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
if (trim($line) === 'y') {
    rename($legacyConfigFile, $legacyConfigFile . '.bak');
    echo "Old configuration file renamed to: {$legacyConfigFile}.bak\n";
} else {
    echo "Old configuration file preserved as is.\n";
    echo "Note: In version 4.0.0, both configuration formats will work, but the new format will be preferred.\n";
    echo "In future versions, only the new configuration format will be supported.\n";
}
fclose($handle);

echo "\n=====================================================================\n";
echo "🎉 Migration successfully completed!\n";
echo "=====================================================================\n";
echo "\nYour migrated configuration includes only your customized settings.\n";
echo "All other settings will use the defaults from settings.defaults.php.\n";
