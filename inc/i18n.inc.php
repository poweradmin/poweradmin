<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://rejo.zenger.nl/poweradmin> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2011  Poweradmin Development Team <http://www.poweradmin.org/credits>
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

if(file_exists('inc/config.inc.php')) {
	include_once("inc/config.inc.php");
} else {
	$iface_lang = 'en_EN';
}

setlocale(LC_ALL, $iface_lang, $iface_lang.'.UTF-8');
$gettext_domain = 'messages';
bindtextdomain($gettext_domain, "./locale");
textdomain($gettext_domain);
@putenv('LANG='.$iface_lang);
@putenv('LANGUAGE='.$iface_lang);
?>
