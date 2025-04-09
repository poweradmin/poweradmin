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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;

require_once __DIR__ . '/vendor/autoload.php';

$config = ConfigurationManager::getInstance();
$config->initialize();

$db_type = $config->get('database', 'type');
$pdns_db_name = $config->get('database', 'pdns_name');
$records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

$credentials = [
    'db_host' => $config->get('database', 'host'),
    'db_port' => $config->get('database', 'port'),
    'db_user' => $config->get('database', 'user'),
    'db_pass' => $config->get('database', 'password'),
    'db_name' => $config->get('database', 'name'),
    'db_charset' => $config->get('database', 'charset'),
    'db_collation' => $config->get('database', 'collation'),
    'db_type' => $db_type,
    'db_file' => $config->get('database', 'file'),
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

/**
 * Parse, trim and validate a comma-separated list of IPs by type.
 *
 * @param string $raw_ip_input Comma-separated IP string
 * @param string $type 'A' or 'AAAA'
 *
 * @return array Filtered list of valid IPs
 */
function extract_valid_ips(string $raw_ip_input, string $type): array
{
    $ip_array = array_map('trim', explode(',', $raw_ip_input));
    return array_filter($ip_array, function ($ip) use ($type) {
        return valid_ip_address($ip) === $type;
    });
}

/** Synchronize A or AAAA DNS records with a new set of IP addresses
 *
 * @param object $db PDO database connection
 * @param string $records_table Name of the records table
 * @param int $domain_id ID of the domain/zone
 * @param string $hostname Fully-qualified domain name to update
 * @param string $type Record type ('A' or 'AAAA')
 * @param array $new_ips List of new IP addresses to apply
 *
 * @return bool True if any changes were made, false otherwise
 */
function sync_dns_records($db, $records_table, int $domain_id, string $hostname, string $type, array $new_ips): bool 
{
    $zone_updated = false;

    $existing = [];
    $query = $db->prepare("SELECT id, content FROM $records_table WHERE domain_id = :domain_id AND name = :hostname AND type = :type");
    $query->execute([':domain_id' => $domain_id, ':hostname' => $hostname, ':type' => $type]);
    while ($row = $query->fetch()) {
        $existing[$row['content']] = $row['id'];
    }

    foreach ($new_ips as $ip) {
        if (isset($existing[$ip])) {
            unset($existing[$ip]);
        } else {
            $insert = $db->prepare("INSERT INTO $records_table (domain_id, name, type, content, ttl, prio, change_date)
                VALUES (:domain_id, :hostname, :type, :ip, 60, NULL, UNIX_TIMESTAMP())");
            $insert->execute([
                ':domain_id' => $domain_id,
                ':hostname' => $hostname,
                ':type' => $type,
                ':ip' => $ip
            ]);
            $zone_updated = true;
        }
    }

    foreach ($existing as $ip => $record_id) {
        $delete = $db->prepare("DELETE FROM $records_table WHERE id = :id");
        $delete->execute([':id' => $record_id]);
        $zone_updated = true;
    }

    return $zone_updated;
}

if (!(isset($_SERVER)) && !$_SERVER['HTTP_USER_AGENT']) {
    return status_exit('badagent');
}

// Grab username & password based on HTTP auth, alternatively the query string
$auth_username = $_SERVER['PHP_AUTH_USER'] ?? $_REQUEST['username'] ?? null;
$auth_password = $_SERVER['PHP_AUTH_PW'] ?? $_REQUEST['password'] ?? null;

// If we still don't have a username, throw up
if (!isset($auth_username)) {
    header('WWW-Authenticate: Basic realm="DNS Update"');
    header('HTTP/1.0 401 Unauthorized');
    return status_exit('badauth');
}

$username = safe($db, $db_type, $auth_username);
$hostname = safe($db, $db_type, $_REQUEST['hostname']);

// === Dynamic IP handling starts here ===

$given_ip = $_REQUEST['myip'] ?? $_REQUEST['ip'] ?? '';
$given_ip6 = $_REQUEST['myip6'] ?? $_REQUEST['ip6'] ?? '';

// Handle special case: "whatismyip"
if ($given_ip === 'whatismyip') {
    if (valid_ip_address($_SERVER['REMOTE_ADDR']) === 'A') {
        $given_ip = $_SERVER['REMOTE_ADDR'];
    } elseif (valid_ip_address($_SERVER['REMOTE_ADDR']) === 'AAAA' && !$given_ip6) {
        $given_ip6 = $_SERVER['REMOTE_ADDR'];
        $given_ip = '';
    }
}
if ($given_ip6 === 'whatismyip') {
    if (valid_ip_address($_SERVER['REMOTE_ADDR']) === 'AAAA') {
        $given_ip6 = $_SERVER['REMOTE_ADDR'];
    }
}

// Validate hostname
if (!strlen($hostname)) {
    return status_exit('notfqdn');
}

// Parse and validate comma-separated IP lists
$dualstack_update = isset($_REQUEST['dualstack_update']) && $_REQUEST['dualstack_update'] === '1';
$ip_v4_input = safe($db, $db_type, $given_ip);
$ip_v6_input = safe($db, $db_type, $given_ip6);

$ip_v4_list = extract_valid_ips($ip_v4_input, 'A');
$ip_v6_list = extract_valid_ips($ip_v6_input, 'AAAA');

sort($ip_v4_list);
sort($ip_v6_list);

// Validate IP input: at least one valid IPv4 or IPv6 address must be present
if (empty($ip_v4_list) && empty($ip_v6_list)) {
    return status_exit('dnserr');
}

// Authenticate user and check permissions
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
    $config->get('security', 'password_encryption'),
    $config->get('security', 'password_cost')
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

    if ($dualstack_update || !empty($ip_v4_list)) {
        if (sync_dns_records($db, $records_table, $zone['domain_id'], $hostname, 'A', $ip_v4_list)) {
            $zone_updated = true;
            $was_updated = true;
        }
    }

    if ($dualstack_update || !empty($ip_v6_list)) {
        if (sync_dns_records($db, $records_table, $zone['domain_id'], $hostname, 'AAAA', $ip_v6_list)) {
            $zone_updated = true;
            $was_updated = true;
        }
    }

    if ($zone_updated) {
        $dnsRecord = new DnsRecord($db, $config);
        $dnsRecord->update_soa_serial($zone['domain_id']);
    }
}

return (($was_updated || $no_update_necessary) ? status_exit('good') : status_exit('!yours'));
