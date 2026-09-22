<?php

require_once __DIR__ . '/vendor/autoload.php';

use Poweradmin\Application\Boot\BootOptions;
use Poweradmin\Application\Boot\Kernel;
use Poweradmin\Application\Service\DynamicDnsRequestFactory;
use Poweradmin\Domain\Service\Dns\DynamicDnsHelper;
use Poweradmin\Infrastructure\Session\SessionActor;
use Symfony\Component\HttpFoundation\Request;

$request = Request::createFromGlobals();

// Booted like the web app (same credentials, same db_ssl* settings) but without a session
$context = Kernel::boot(BootOptions::Script);
$config = $context->config;

// The same per-request service graph the web controllers use, so the backend
// provider, repositories and permission cache are built once
$services = $context->services(new SessionActor());
$repository = $services->repositoryFactory()->createDynamicDnsRepository($services->soaRecordManager());
$updateService = DynamicDnsRequestFactory::createUpdateService($context->database(), $config, $repository, $services->permissionService());

$result = $updateService->processUpdate(DynamicDnsRequestFactory::fromHttpRequest($request, $config));
echo DynamicDnsHelper::statusMessage($result, $request->query->has('verbose'));
