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

namespace Poweradmin\Domain\Config;

class UiConfigDefaults
{
    public static function getDefaults(): array
    {
        return [
            // User Interface settings
            'show_generated_passwords' => true,  // Whether to show generated passwords in the admin UI

            // Table display settings
            'show_record_id_column' => true,     // Whether to show ID columns in tables and forms

            // Form layout settings
            'position_record_form_top' => false, // Whether to show the "Add new record" form at the top of the page
            'position_save_button_top' => false, // Whether to show the "Save changes" button at the top of the page

            // Visual settings (for future expansion)
            'records_per_page' => 50,            // Default number of items to show per page
            'default_sort_order' => 'asc',       // Default sort direction
        ];
    }
}