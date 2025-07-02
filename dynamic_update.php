<?php

require_once __DIR__ . '/vendor/autoload.php';

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DynamicDnsAuthenticationService;
use Poweradmin\Domain\Service\DynamicDnsHelper;
use Poweradmin\Domain\Service\DynamicDnsUpdateService;
use Poweradmin\Domain\Service\DynamicDnsValidationService;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Repository\DynamicDnsRepository;
use Symfony\Component\HttpFoundation\Request;

// Main execution code
$request = Request::createFromGlobals();

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

// Initialize services
$dnsRecord = new DnsRecord($db, $config);
$repository = new DynamicDnsRepository($db, $dnsRecord, $records_table, $config);

$validationService = new DynamicDnsValidationService($config);

$userAuthService = new UserAuthenticationService(
    $config->get('security', 'password_encryption'),
    $config->get('security', 'password_cost')
);
$authenticationService = new DynamicDnsAuthenticationService($repository, $userAuthService);

$updateService = new DynamicDnsUpdateService(
    $validationService,
    $authenticationService,
    $repository
);

// Create request value object and process update
$dynamicDnsRequest = DynamicDnsRequest::fromHttpRequest($request);
$result = $updateService->processUpdate($dynamicDnsRequest);

// Output result and exit
DynamicDnsHelper::statusExit($result);
