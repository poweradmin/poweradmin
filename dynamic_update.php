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
 * Script that handles requests to update DNS records, required for clients
 * with dynamic ip addresses
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\AppConfiguration;

require_once __DIR__ . '/vendor/autoload.php';

$config = new AppConfiguration();
$db_type = $config->get('db_type');

$pdns_db_name = $config->get('pdns_db_name');
$records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

$credentials = [
    'db_host' => $config->get('db_host'),
    'db_port' => $config->get('db_port'),
    'db_user' => $config->get('db_user'),
    'db_pass' => $config->get('db_pass'),
    'db_name' => $config->get('db_name'),
    'db_charset' => $config->get('db_charset'),
    'db_collation' => $config->get('db_collation'),
    'db_type' => $db_type,
    'db_file' => $config->get('db_file'),
];

$databaseConnection = new PDODatabaseConnection();
$databaseService = new DatabaseService($databaseConnection);
$db = $databaseService->connect($credentials);

/** Make sql query safe
 *
 * @param $db
 * @param $db_type
 * @param mixed $value Unsafe Value
 *
 * @return string $value Safe Value
 */
function safe($db, $db_type, mixed $value): string
{
    if ($db_type == 'mysql' || $db_type == 'sqlite' || $db_type == 'pgsql') {
        $value = $db->quote($value, 'text');
        $value = substr($value, 1, -1); // remove quotes
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
function status_exit(string $status): bool
{
    $verbose_codes = array(
        'badagent' => 'Your user agent is not valid.',
        'badauth' => 'No username available.',
        'badauth2' => 'Invalid username or password.  Authentication failed.',
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

/** Check whether the given address is an IP address
 *
 * @param string $ip Given IP address
 *
 * @return int|string A if IPv4, AAAA if IPv6 or 0 if invalid
 */
function valid_ip_address(string $ip): int|string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $value = 'A';
    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $value = 'AAAA';
    } else {
        $value = 0;
    }
    return $value;
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

$username = safe($db, $db_type, $auth_username);
$hostname = safe($db, $db_type, $_REQUEST['hostname']);

// Grab IP to use
$given_ip = "";
$given_ip6 = "";
if (!empty($_REQUEST['myip'])) {
    $given_ip = $_REQUEST['myip'];
} elseif (!empty($_REQUEST['ip'])) {
    $given_ip = $_REQUEST['ip'];
}
if (!empty($_REQUEST['myip6'])) {
    $given_ip6 = $_REQUEST['myip6'];
} elseif (!empty($_REQUEST['ip6'])) {
    $given_ip6 = $_REQUEST['ip6'];
}

if (valid_ip_address($given_ip) === 'AAAA') {
    $given_ip6 = $given_ip;
}
// Look for tag to grab the IP we're coming from
if (($given_ip6 == "whatismyip") && (valid_ip_address($_SERVER['REMOTE_ADDR']) === 'AAAA')) {
    $given_ip6 = $_SERVER['REMOTE_ADDR'];
}
if (($given_ip == "whatismyip") && (valid_ip_address($_SERVER['REMOTE_ADDR']) === 'A')) {
    $given_ip = $_SERVER['REMOTE_ADDR'];
} elseif (($given_ip == "whatismyip") && (valid_ip_address($_SERVER['REMOTE_ADDR']) === 'AAAA') && (!(valid_ip_address($given_ip6) === 'AAAA'))) {
    $given_ip6 = $_SERVER['REMOTE_ADDR'];
}

// Finally get safe version of the IP
$ip = safe($db, $db_type, $given_ip);
$ip6 = safe($db, $db_type, $given_ip6);
// Check it's ok...
if ((!valid_ip_address($ip)) && (!valid_ip_address($ip6))) {
    return status_exit('dnserr');
}

if (!strlen($hostname)) {
    return status_exit('notfqdn');
}

$user = $db->queryRow("SELECT users.id, users.password FROM users, perm_templ, perm_templ_items, perm_items 
                        WHERE users.username='$username'
                        AND users.active=1 
                        AND perm_templ.id = users.perm_templ 
                        AND perm_templ_items.templ_id = perm_templ.id 
                        AND perm_items.id = perm_templ_items.perm_id 
                        AND (
                            perm_items.name = 'zone_content_edit_own'
                            OR perm_items.name = 'zone_content_edit_own_as_client'
                            OR perm_items.name = 'zone_content_edit_others'
                        )");

$userAuthService = new UserAuthenticationService(
    $config->get('password_encryption'),
    $config->get('password_encryption_cost')
);
if (!$user || !$userAuthService->verifyPassword($auth_password, $user['password'])) {
    return status_exit('badauth2');
}

$zones_query = $db->prepare('SELECT domain_id FROM zones WHERE owner=:user_id');
$zones_query->execute([':user_id' => $user['id']]);
$was_updated = false;
$no_update_necessary = false;

while ($zone = $zones_query->fetch()) {
    $zone_updated = false;
    $name_query = $db->prepare("SELECT name, type, content FROM $records_table WHERE domain_id=:domain_id and (type = 'A' OR type = 'AAAA')");
    $name_query->execute([':domain_id' => $zone["domain_id"]]);

    while ($record = $name_query->fetch()) {
        if ($hostname == $record['name']) {
            if (($record['type'] == 'A') && (valid_ip_address($ip) === 'A')) {
                if ($ip == $record['content']) {
                    $no_update_necessary = true;
                } else {
                    $update_query = $db->prepare("UPDATE $records_table SET content =:ip where name=:record_name and type='A'");
                    $update_query->execute([':ip' => $ip, ':record_name' => $record['name']]);
                    $zone_updated = true;
                    $was_updated = true;
                }
            } elseif (($record['type'] == 'AAAA') && (valid_ip_address($ip6) === 'AAAA')) {
                if ($ip6 == $record['content']) {
                    $no_update_necessary = true;
                } else {
                    $update_query = $db->prepare("UPDATE $records_table SET content =:ip6 where name=:record_name and type='AAAA'");
                    $update_query->execute([':ip6' => $ip6, ':record_name' => $record['name']]);
                    $zone_updated = true;
                    $was_updated = true;
                }
            }
        }
    }
    if ($zone_updated) {
        $dnsRecord = new DnsRecord($db, $config);
        $dnsRecord->update_soa_serial($zone['domain_id']);
    }
}

return (($was_updated || $no_update_necessary) ? status_exit('good') : status_exit('!yours'));
