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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Application\Service\SamlService;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;

class SamlMetadataController extends BaseController
{
    private SamlService $samlService;
    private Request $httpRequest;

    public function __construct(array $request)
    {
        // Don't authenticate - metadata should be publicly accessible
        parent::__construct($request, false);

        $this->httpRequest = new Request();

        // Initialize SAML services
        $logHandler = LoggerHandlerFactory::create($this->config->getAll());
        $logLevel = $this->config->get('logging', 'level', 'info');
        $logger = new Logger($logHandler, $logLevel);

        $samlConfigService = new SamlConfigurationService($this->config, $logger);
        $userProvisioningService = new UserProvisioningService($this->db, $this->config, $logger);

        $this->samlService = new SamlService(
            $this->config,
            $samlConfigService,
            $userProvisioningService,
            $logger,
            $this->db,
            $this->httpRequest
        );
    }

    public function run(): void
    {
        // Check if SAML is enabled
        if (!$this->samlService->isEnabled()) {
            http_response_code(404);
            echo 'SAML authentication is not enabled';
            return;
        }

        // Get provider parameter
        $providerId = $this->httpRequest->getQueryParam('provider');

        // If no provider specified, get the first available provider
        if (empty($providerId)) {
            $availableProviders = $this->samlService->getAvailableProviders();
            if (empty($availableProviders)) {
                http_response_code(404);
                echo 'No SAML providers are configured';
                return;
            }

            // Use the first available provider
            $providerId = array_key_first($availableProviders);
        }

        // Validate provider
        if (!$this->validateProvider($providerId)) {
            http_response_code(404);
            echo 'Invalid SAML provider';
            return;
        }

        try {
            // Generate and serve metadata
            $metadata = $this->samlService->generateMetadata($providerId);

            // Set appropriate headers for XML content
            header('Content-Type: application/xml; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

            echo $metadata;
        } catch (\Exception $e) {
            http_response_code(500);
            echo 'Error generating SAML metadata: ' . $e->getMessage();
        }
    }

    private function validateProvider(string $provider): bool
    {
        // Sanitize provider ID - only allow alphanumeric characters and underscores
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $provider)) {
            return false;
        }

        // Check if provider is actually available
        $availableProviders = $this->samlService->getAvailableProviders();
        return isset($availableProviders[$provider]);
    }
}
