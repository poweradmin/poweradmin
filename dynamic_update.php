<?php

require_once __DIR__ . '/vendor/autoload.php';

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DynamicDnsHelper;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;

// Main execution code
$config = ConfigurationManager::getInstance();
$config->initialize();

$db_type = $config->get('database', 'type');
$tableNameService = new TableNameService($config);
$records_table = $tableNameService->getTable(PdnsTable::RECORDS);

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
    'db_debug' => $config->get('database', 'debug'),
];

$databaseConnection = new PDODatabaseConnection();
$databaseService = new DatabaseService($databaseConnection);
$db = $databaseService->connect($credentials);

if (!isset($_SERVER['HTTP_USER_AGENT']) || empty($_SERVER['HTTP_USER_AGENT'])) {
    return DynamicDnsHelper::statusExit('badagent');
}

// Grab username & password based on HTTP auth, alternatively the query string
$auth_username = $_SERVER['PHP_AUTH_USER'] ?? $_REQUEST['username'] ?? null;
$auth_password = $_SERVER['PHP_AUTH_PW'] ?? $_REQUEST['password'] ?? null;

// If we still don't have a username, throw up
if (!isset($auth_username)) {
    header('WWW-Authenticate: Basic realm="DNS Update"');
    header('HTTP/1.0 401 Unauthorized');
    return DynamicDnsHelper::statusExit('badauth');
}

$username = $auth_username;
$hostname = $_REQUEST['hostname'];

// === Dynamic IP handling starts here ===

$given_ip = $_REQUEST['myip'] ?? $_REQUEST['ip'] ?? '';
$given_ip6 = $_REQUEST['myip6'] ?? $_REQUEST['ip6'] ?? '';

// Handle special case: "whatismyip"
if ($given_ip === 'whatismyip') {
    if (DynamicDnsHelper::validIpAddress($_SERVER['REMOTE_ADDR']) === RecordType::A) {
        $given_ip = $_SERVER['REMOTE_ADDR'];
    } elseif (DynamicDnsHelper::validIpAddress($_SERVER['REMOTE_ADDR']) === RecordType::AAAA && !$given_ip6) {
        $given_ip6 = $_SERVER['REMOTE_ADDR'];
        $given_ip = '';
    }
}
if ($given_ip6 === 'whatismyip') {
    if (DynamicDnsHelper::validIpAddress($_SERVER['REMOTE_ADDR']) === RecordType::AAAA) {
        $given_ip6 = $_SERVER['REMOTE_ADDR'];
    }
}

// Validate hostname
if (!strlen($hostname)) {
    return DynamicDnsHelper::statusExit('notfqdn');
}

// Parse and validate comma-separated IP lists
$dualstack_update = isset($_REQUEST['dualstack_update']) && $_REQUEST['dualstack_update'] === '1';
$ip_v4_input = $given_ip;
$ip_v6_input = $given_ip6;

$ip_v4_list = DynamicDnsHelper::extractValidIps($ip_v4_input, RecordType::A);
$ip_v6_list = DynamicDnsHelper::extractValidIps($ip_v6_input, RecordType::AAAA);

sort($ip_v4_list);
sort($ip_v6_list);

// Validate IP input: at least one valid IPv4 or IPv6 address must be present
if (empty($ip_v4_list) && empty($ip_v6_list)) {
    return DynamicDnsHelper::statusExit('dnserr');
}

// Authenticate user and check permissions
$auth_query = $db->prepare("SELECT users.id, users.password FROM users, perm_templ, perm_templ_items, perm_items 
                        WHERE users.username = :username
                        AND users.active = 1 
                        AND perm_templ.id = users.perm_templ 
                        AND perm_templ_items.templ_id = perm_templ.id 
                        AND perm_items.id = perm_templ_items.perm_id 
                        AND (
                            perm_items.name = 'zone_content_edit_own'
                            OR perm_items.name = 'zone_content_edit_own_as_client'
                            OR perm_items.name = 'zone_content_edit_others'
                        )");
$auth_query->execute([':username' => $username]);
$user = $auth_query->fetch(PDO::FETCH_ASSOC);

$userAuthService = new UserAuthenticationService(
    $config->get('security', 'password_encryption'),
    $config->get('security', 'password_cost')
);

if (!$user || !$userAuthService->verifyPassword($auth_password, $user['password'])) {
    return DynamicDnsHelper::statusExit('badauth2');
}

$zones_query = $db->prepare('SELECT domain_id FROM zones WHERE owner=:user_id');
$zones_query->execute([':user_id' => $user['id']]);
$was_updated = false;
$no_update_necessary = false;

while ($zone = $zones_query->fetch()) {
    $zone_updated = false;

    if ($dualstack_update || !empty($ip_v4_list)) {
        if (DynamicDnsHelper::syncDnsRecords($db, $records_table, $zone['domain_id'], $hostname, RecordType::A, $ip_v4_list)) {
            $zone_updated = true;
            $was_updated = true;
        }
    }

    if ($dualstack_update || !empty($ip_v6_list)) {
        if (DynamicDnsHelper::syncDnsRecords($db, $records_table, $zone['domain_id'], $hostname, RecordType::AAAA, $ip_v6_list)) {
            $zone_updated = true;
            $was_updated = true;
        }
    }

    if ($zone_updated) {
        $dnsRecord = new DnsRecord($db, $config);
        $dnsRecord->updateSOASerial($zone['domain_id']);
    }
}

return (($was_updated || $no_update_necessary) ? DynamicDnsHelper::statusExit('good') : DynamicDnsHelper::statusExit('!yours'));
