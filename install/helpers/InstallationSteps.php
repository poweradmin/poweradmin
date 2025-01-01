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

namespace PoweradminInstall;

class InstallationSteps
{
    public const STEP_CHOOSE_LANGUAGE = 1;
    public const STEP_GETTING_READY = 2;
    public const STEP_CONFIGURING_DATABASE = 3;
    public const STEP_SETUP_ACCOUNT_AND_NAMESERVERS = 4;
    public const STEP_CREATE_LIMITED_RIGHTS_USER = 5;
    public const STEP_CREATE_CONFIGURATION_FILE = 6;
    public const STEP_INSTALLATION_COMPLETE = 7;
}
