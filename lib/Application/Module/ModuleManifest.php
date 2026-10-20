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

namespace Poweradmin\Application\Module;

use Poweradmin\Domain\Module\ModuleInterface;
use Poweradmin\Module\CsvExport\CsvExportModule;
use Poweradmin\Module\DnsWizard\DnsWizardModule;
use Poweradmin\Module\EmailPreviews\EmailPreviewsModule;
use Poweradmin\Module\Rdap\RdapModule;
use Poweradmin\Module\SecondaryZoneImport\SecondaryZoneImportModule;
use Poweradmin\Module\Whois\WhoisModule;
use Poweradmin\Module\ZoneImportExport\ZoneImportExportModule;

/**
 * The bundled modules, keyed by the name used under modules.<name> in the configuration.
 *
 * Adding a module means adding a line here; ModuleRegistry reads this list.
 */
final class ModuleManifest
{
    /** @var array<string, class-string<ModuleInterface>> */
    public const MODULES = [
        'csv_export' => CsvExportModule::class,
        'zone_import_export' => ZoneImportExportModule::class,
        'whois' => WhoisModule::class,
        'rdap' => RdapModule::class,
        'dns_wizards' => DnsWizardModule::class,
        'email_previews' => EmailPreviewsModule::class,
        'secondary_zone_import' => SecondaryZoneImportModule::class,
    ];

    private function __construct()
    {
    }
}
