<?php

require_once __DIR__ . '/vendor/autoload.php';

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\LoginAttemptService;
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
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\ApiDynamicDnsRepository;
use Poweradmin\Infrastructure\Repository\SqlDynamicDnsRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Symfony\Component\HttpFoundation\Request;

$request = Request::createFromGlobals();

$config = ConfigurationManager::getInstance();
$config->initialize();

require_once __DIR__ . '/lib/Application/Helpers/StartupHelpers.php';
initializeTimezone($config);

$tableNameService = new TableNameService($config);
$records_table = $tableNameService->getTable(PdnsTable::RECORDS);
$domains_table = $tableNameService->getTable(PdnsTable::DOMAINS);

$credentials = [
    'db_host' => $config->get('database', 'host'),
    'db_port' => $config->get('database', 'port'),
    'db_user' => $config->get('database', 'user'),
    'db_pass' => $config->get('database', 'password'),
    'db_name' => $config->get('database', 'name'),
    'db_charset' => $config->get('database', 'charset'),
    'db_collation' => $config->get('database', 'collation'),
    'db_type' => $config->get('database', 'type'),
    'db_file' => $config->get('database', 'file'),
    'db_debug' => $config->get('database', 'debug'),
];

$db = (new DatabaseService(new PDODatabaseConnection()))->connect($credentials);

$dnsRecord = new DnsRecord($db, $config);
$backendProvider = DnsBackendProviderFactory::create($db, $config);
$repository = $backendProvider->isApiBackend()
    ? new ApiDynamicDnsRepository($db, $dnsRecord, $backendProvider)
    : new SqlDynamicDnsRepository($db, $dnsRecord, $records_table, $domains_table);

$userAuthService = new UserAuthenticationService(
    $config->get('security', 'password_encryption', 'bcrypt'),
    $config->get('security', 'password_cost', 12)
);

$updateService = new DynamicDnsUpdateService(
    new DynamicDnsValidationService($config),
    new DynamicDnsAuthenticationService($repository, $userAuthService, new LoginAttemptService($db, $config)),
    $repository,
    new LegacyLogger($db),
    new IpAddressRetriever($_SERVER)
);

$result = $updateService->processUpdate(DynamicDnsRequest::fromHttpRequest($request));
DynamicDnsHelper::statusExit($result);
