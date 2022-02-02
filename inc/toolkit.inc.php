<?php
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *  Toolkit functions
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

include_once 'config-me.inc.php';
include_once 'tlds.inc.php';
include_once 'record-types.inc.php';
include_once 'zone-types.inc.php';

if (!@include_once('config.inc.php')) {
    error(_('You have to create a config.inc.php!'));
}

require_once 'benchmark.php';
require_once 'error.inc.php';
require_once 'countrycodes.inc.php';

session_start();

// Database connection
require_once 'database.inc.php';
require_once 'plugin.inc.php';
require_once 'i18n.inc.php';
require_once 'auth.inc.php';
require_once 'users.inc.php';

$db = dbConnect();
require_once 'dns.inc.php';
require_once 'record.inc.php';
require_once 'dnssec.inc.php';
require_once 'templates.inc.php';

do_hook('authenticate');
