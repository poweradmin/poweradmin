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

// Ensure this script is only run from the command line
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo "This script can only be executed from the command line.";
    exit(1);
}

require_once __DIR__ . '/../lib/Infrastructure/Configuration/ConfigurationManager.php';

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

// Create ConfigurationManager instance
$configManager = ConfigurationManager::getInstance();
$configManager->initialize();

// Get the migrated configuration
$settings = $configManager->getAll();

// Create the new configuration file
$configContent = "<?php\n";
$configContent .= "/**\n";
$configContent .= " * Poweradmin Settings Configuration File\n";
$configContent .= " * \n";
$configContent .= " * This file was automatically migrated from the old configuration format.\n";
$configContent .= " * Generated on: " . date('Y-m-d H:i:s') . "\n";
$configContent .= " */\n\n";
$configContent .= "return " . var_export($settings, true) . ";\n";

// Write the file
if (!is_dir(dirname($newConfigFile))) {
    mkdir(dirname($newConfigFile), 0755, true);
}

file_put_contents($newConfigFile, $configContent);

echo "Configuration successfully migrated to: $newConfigFile\n";
echo "Please review the new configuration file and make any necessary adjustments.\n";
echo "You may keep the old configuration file for backup, but the application will now use the new format.\n";

// Optionally rename the old config file to indicate it's been migrated
echo "Do you want to rename the old configuration file to {$legacyConfigFile}.bak? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
if (trim($line) === 'y') {
    rename($legacyConfigFile, $legacyConfigFile . '.bak');
    echo "Old configuration file renamed to: {$legacyConfigFile}.bak\n";
}
fclose($handle);

echo "Migration complete!\n";