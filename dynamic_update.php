<?php

require_once __DIR__ . '/vendor/autoload.php';

use Poweradmin\Application\Boot\BootOptions;
use Poweradmin\Application\Boot\Kernel;
use Poweradmin\Application\Service\Record\DynamicDnsRequestFactory;
use Poweradmin\Domain\Service\Dns\DynamicDnsHelper;
use Poweradmin\Infrastructure\Session\SessionActor;
use Symfony\Component\HttpFoundation\Request;

// Every answer is a per-request status word; a shared cache must not replay it
header('Cache-Control: no-store');

$request = Request::createFromGlobals();

// A browser replaying cached credentials on a cross-site GET sends Fetch metadata; DDNS clients send none
$fetchSite = $request->headers->get('Sec-Fetch-Site');
if ($fetchSite !== null && !in_array(strtolower(trim($fetchSite)), ['same-origin', 'none'], true)) {
    http_response_code(403);
    echo DynamicDnsHelper::statusMessage('badauth', $request->query->has('verbose'));
    exit;
}

// Booted like the web app (same credentials, same db_ssl* settings) but without a session
$context = Kernel::boot(BootOptions::Script);
$config = $context->config;

// The same per-request service graph the web controllers use, so the backend
// provider, repositories and permission cache are built once
$services = $context->services(new SessionActor($context->session));
$repository = $services->repositoryFactory()->createDynamicDnsRepository($services->soaRecordManager());
$updateService = DynamicDnsRequestFactory::createUpdateService($context->database(), $config, $repository, $services->permissionService(), $services->auditService(), $services->zoneCacheFlusher(), $services->postWriteZoneRectifier());

$result = $updateService->processUpdate(DynamicDnsRequestFactory::fromHttpRequest($request, $config));
echo DynamicDnsHelper::statusMessage($result, $request->query->has('verbose'));
