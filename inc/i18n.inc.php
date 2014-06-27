<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
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
 * Interface localisation helper
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
if (file_exists('inc/config.inc.php')) {
    include_once("inc/config.inc.php");
} else {
    $iface_lang = 'en_EN';
}

if ($iface_lang != 'en_EN') {
    $locale = setlocale(LC_ALL, $iface_lang, $iface_lang . '.UTF-8');
    if ($locale == false) {
        error(ERR_LOCALE_FAILURE);
    }

    $gettext_domain = 'messages';
    bindtextdomain($gettext_domain, "./locale");
    bind_textdomain_codeset($gettext_domain, 'utf-8');
    textdomain($gettext_domain);
    @putenv('LANG=' . $iface_lang);
    @putenv('LANGUAGE=' . $iface_lang);
}
