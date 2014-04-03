<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
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
 * Script that handles requests to update DNS records, required for clients
 * with dynamic ip addresses
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require('inc/config.inc.php');
require('inc/database.inc.php');

$db = dbConnect();

/** Make sql query safe
 *
 * @param mixed $value Unsafe Value
 *
 * @return mixed $value Safe Value
 */
function safe($value) {
    global $db, $db_type, $db_layer;

    if ($db_type == 'mysql') {
        if ($db_layer == 'MDB2') {
            $value = mysql_real_escape_string($value);
        } elseif ($db_layer == 'PDO') {
            $value = $db->quote($value, 'text');
            $value = substr($value, 1, -1); // remove quotes
        }
    } else {
        return status_exit('baddbtype');
    }

    return $value;
}

/** Get exit status message
 *
 * Print verbose status message for request
 *
 * @param string $status Short status message
 *
 * @return boolean false
 */
function status_exit($status) {
    $verbose_codes = array(
        'badagent' => 'Your user agent is not valid.',
        'badauth' => 'Invalid username or password.  Authentication failed.',
        'notfqdn' => 'The hostname you specified was not valid.',
        'dnserr' => 'A DNS error has occurred on our end.  We apologize for any inconvenience.',
        '!yours' => 'The specified hostname does not belong to you.',
        'nohost' => 'The specified hostname does not exist.',
        'good' => 'Your hostname has been updated.',
        '911' => 'A critical error has occurred on our end.  We apologize for any inconvenience.',
        'nochg' => 'This update was identical to your last update, so no changes were made to your hostname configuration.',
        'baddbtype' => 'Unsupported database type',
    );

    if (isset($_REQUEST['verbose'])) {
        $pieces = preg_split('/\s/', $status);
        $status = $verbose_codes[$pieces[0]];
    }
    echo "$status\n";
    return false;
}

if (!(isset($_SERVER)) && !$_SERVER['HTTP_USER_AGENT']) {
    return status_exit('badagent');
}

// Grab username & password based on HTTP auth, alternatively the query string
if (isset($_SERVER['PHP_AUTH_USER'])) {
	$auth_username = $_SERVER['PHP_AUTH_USER'];
} elseif (isset($_REQUEST['username'])) {
	$auth_username = $_REQUEST['username'];
}
if (isset($_SERVER['PHP_AUTH_PW'])) {
	$auth_password = $_SERVER['PHP_AUTH_PW'];
} elseif (isset($_REQUEST['password'])) {
	$auth_password = $_REQUEST['password'];
}

// If we still don't have a username, throw up
if (!isset($auth_username)) {
    header('WWW-Authenticate: Basic realm="DNS Update"');
    header('HTTP/1.0 401 Unauthorized');
    return status_exit('badauth');
}

$username = safe($auth_username);
// FIXME: supports only md5 hashes
$password = md5(safe($auth_password));
$hostname = safe($_REQUEST['hostname']);

// Grab IP to use
$given_ip = "";
if (!empty($_REQUEST['myip'])) {
	$given_ip = $_REQUEST['myip'];

} elseif (!empty($_REQUEST['ip'])) {
	$given_ip = $_REQUEST['ip'];

}
// Look for tag tograb the IP we coming from
if ($given_ip == "whatismyip") {
	$given_ip = $_SERVER['REMOTE_ADDR'];
}
// Finally get save version of the IP
$ip = safe($given_ip);
// Check its ok...
if (!preg_match('/^((?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/i', $ip)) {
    return status_exit('dnserr');
}

if (!strlen($hostname)) {
    return status_exit('notfqdn');
}

$user_query = "
	SELECT
		users.id
	FROM
		users, perm_templ, perm_templ_items, perm_items
	WHERE
		users.username = '$username'
		AND users.password = '$password'
		AND users.active = 1
		AND perm_templ.id = users.perm_templ
		AND perm_templ_items.templ_id = perm_templ.id
		AND perm_items.id = perm_templ_items.perm_id
		AND (
				perm_items.name = 'zone_content_edit_own'
				OR perm_items.name = 'zone_content_edit_others'
		)
";
$user = $db->queryRow($user_query);
if (!$user) {
    return status_exit('badauth');
}

$zones_query = "SELECT domain_id FROM zones WHERE owner='{$user["id"]}'";
$zones_result = $db->query($zones_query);
$was_updated = false;

while ($zone = $zones_result->fetchRow()) {
    $name_query = "SELECT name FROM records WHERE domain_id='{$zone["domain_id"]}' and type = 'A'";
    $result = $db->query($name_query);

    while ($record = $result->fetchRow()) {
        if ($hostname == $record['name']) {
            $update_query = "UPDATE records SET content ='{$ip}' where name='{$record["name"]}' and type='A'";
            $update_result = $db->query($update_query);
            $was_updated = true;
        }
    }
}

return ($was_updated ? status_exit('good') : status_exit('!yours'));
