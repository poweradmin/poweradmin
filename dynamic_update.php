<?php

require_once __DIR__ . '/vendor/autoload.php';

use Poweradmin\Application\Bootstrap;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\DynamicDnsRequestFactory;
use Poweradmin\Infrastructure\Database\DatabaseCredentialMapper;
use Poweradmin\Domain\Service\Dns\DynamicDnsHelper;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Session\SessionActor;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

$request = Request::createFromGlobals();

$config = ConfigurationManager::getInstance();
$config->initialize();
Bootstrap::initializeTimezone($config);

// Use the shared credential mapper so DDNS honors the same db_ssl* settings as the
// web app; a hand-built array here silently dropped them and connected in plaintext.
$credentials = DatabaseCredentialMapper::mapCredentials($config);

$db = (new DatabaseService(new PDODatabaseConnection()))->connect($credentials);

// The same per-request service graph the web controllers use, so the backend
// provider, repositories and permission cache are built once
$services = new ControllerServiceFactory($db, $config, new NullLogger(), new SessionActor());
$repository = $services->repositoryFactory()->createDynamicDnsRepository($services->soaRecordManager());
$updateService = DynamicDnsRequestFactory::createUpdateService($db, $config, $repository, $services->permissionService());

$result = $updateService->processUpdate(DynamicDnsRequestFactory::fromHttpRequest($request, $config));
echo DynamicDnsHelper::statusMessage($result, $request->query->has('verbose'));
