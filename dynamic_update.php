<?php

require_once __DIR__ . '/vendor/autoload.php';

use Poweradmin\Application\Bootstrap;
use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\DynamicDnsRequestFactory;
use Poweradmin\Application\Service\RepositoryFactory;
use Poweradmin\Domain\Service\DatabaseCredentialMapper;
use Poweradmin\Domain\Service\DynamicDnsHelper;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\CanonicalZoneSql;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Symfony\Component\HttpFoundation\Request;

$request = Request::createFromGlobals();

$config = ConfigurationManager::getInstance();
$config->initialize();
CanonicalZoneSql::setRowIdFallback(DnsBackendProviderFactory::isApiBackend($config));

Bootstrap::initializeTimezone($config);

// Use the shared credential mapper so DDNS honors the same db_ssl* settings as the
// web app; a hand-built array here silently dropped them and connected in plaintext.
$credentials = DatabaseCredentialMapper::mapCredentials($config);

$db = (new DatabaseService(new PDODatabaseConnection()))->connect($credentials);

$backendProvider = DnsBackendProviderFactory::create($db, $config);
$soaRecordManager = DnsServiceFactory::createSOARecordManager($db, $config, $backendProvider);
$repository = (new RepositoryFactory($db, $config, $backendProvider))->createDynamicDnsRepository($soaRecordManager);

$updateService = DynamicDnsRequestFactory::createUpdateService($db, $config, $repository);

$result = $updateService->processUpdate(DynamicDnsRequestFactory::fromHttpRequest($request));
DynamicDnsHelper::statusExit($result, $request->query->has('verbose'));
