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
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Web interface header
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;

global $iface_style;
global $iface_title;
global $ignore_install_dir;
global $session_key;

header('Content-type: text/html; charset=utf-8');

$vars = [
    'iface_title' => $iface_title,
    'iface_style' => $iface_style == 'example' ? 'ignite' : $iface_style,
    'file_version' => time(),
    'custom_header' => file_exists('templates/custom/header.html'),
    'install_error' => !$ignore_install_dir && file_exists('install') ? _('The <a href="install/">install/</a> directory exists, you must remove it first before proceeding.') : false,
];

$app = AppFactory::create();
$dblog_use = $app->config('dblog_use');

if (isset($_SESSION["userid"])) {
    $perm_is_godlike = do_hook('verify_permission', 'user_is_ueberuser');

    $vars = array_merge($vars, [
        'user_logged_in' => isset($_SESSION["userid"]),
        'perm_search' => do_hook('verify_permission', 'search'),
        'perm_view_zone_own' => do_hook('verify_permission', 'zone_content_view_own'),
        'perm_view_zone_other' => do_hook('verify_permission', 'zone_content_view_others'),
        'perm_supermaster_view' => do_hook('verify_permission', 'supermaster_view'),
        'perm_zone_master_add' => do_hook('verify_permission', 'zone_master_add'),
        'perm_zone_slave_add' => do_hook('verify_permission', 'zone_slave_add'),
        'perm_supermaster_add' => do_hook('verify_permission', 'supermaster_add'),
        'perm_is_godlike' => $perm_is_godlike,
        'perm_templ_perm_edit' => do_hook('verify_permission', 'templ_perm_edit'),
        'perm_add_new' => do_hook('verify_permission', 'user_add_new'),
        'session_key_error' => $perm_is_godlike && $session_key == 'p0w3r4dm1n' ? _('Default session encryption key is used, please set it in your configuration file.') : false,
        'auth_used' => $_SESSION["auth_used"] != "ldap",
        'dblog_use' => $dblog_use
    ]);
}

$app->render('header.html', $vars);
