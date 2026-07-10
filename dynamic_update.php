<?php

require_once __DIR__ . '/vendor/autoload.php';

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\RepositoryFactory;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Service\DatabaseCredentialMapper;
use Poweradmin\Domain\Service\DynamicDnsAuthenticationService;
use Poweradmin\Domain\Service\DynamicDnsHelper;
use Poweradmin\Domain\Service\DynamicDnsUpdateService;
use Poweradmin\Domain\Service\DynamicDnsValidationService;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Symfony\Component\HttpFoundation\Request;

$request = Request::createFromGlobals();

$config = ConfigurationManager::getInstance();
$config->initialize();

require_once __DIR__ . '/lib/Application/Helpers/StartupHelpers.php';
initializeTimezone($config);

// Use the shared credential mapper so DDNS honors the same db_ssl* settings as the
// web app; a hand-built array here silently dropped them and connected in plaintext.
$credentials = DatabaseCredentialMapper::mapCredentials($config);

$db = (new DatabaseService(new PDODatabaseConnection()))->connect($credentials);

$backendProvider = DnsBackendProviderFactory::create($db, $config);
$soaRecordManager = DnsServiceFactory::createSOARecordManager($db, $config, $backendProvider);
$repository = (new RepositoryFactory($db, $config, $backendProvider))->createDynamicDnsRepository($soaRecordManager);

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
DynamicDnsHelper::statusExit($result, $request->query->has('verbose'));
