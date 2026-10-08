<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit\Api\V2;

use Poweradmin\Application\Controller\Api\V2\ZoneDnssecKeysController;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Zone\DnssecKeyService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TestHelpers\FakeConfiguration;

class TestableZoneDnssecKeysController extends ZoneDnssecKeysController
{
    private DnssecKeyService $keyService;
    public ?PdnsCapabilities $capabilities = null;

    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(array $request = [], array $pathParameters = [])
    {
        $this->request = new Request();
        $this->pathParameters = $pathParameters;
        $this->authenticatedUserId = 1;
        $this->config = new FakeConfiguration();
    }

    public function setDomainRepository(DomainRepositoryInterface $repository): void
    {
        $this->domainRepository = $repository;
    }

    public function setApiPermissionService(ApiPermissionService $service): void
    {
        $this->apiPermissionService = $service;
    }

    public function setApiClient(?PowerdnsApiClient $client): void
    {
        $this->apiClient = $client;
    }

    public function setKeyService(DnssecKeyService $keyService): void
    {
        $this->keyService = $keyService;
    }

    public function setRequestBody(string $content): void
    {
        $this->request = new Request([], [], [], [], [], [], $content);
    }

    public function callListKeys(): JsonResponse
    {
        return $this->listKeys();
    }

    public function callGetKey(): JsonResponse
    {
        return $this->getKey();
    }

    public function callAddKey(): JsonResponse
    {
        return $this->addKey();
    }

    public function callUpdateKey(): JsonResponse
    {
        return $this->updateKey();
    }

    public function callDeleteKey(): JsonResponse
    {
        return $this->deleteKey();
    }

    public function callImportKey(): JsonResponse
    {
        return $this->importKey();
    }

    protected function keyService(): DnssecKeyService
    {
        return $this->keyService;
    }

    protected function getPdnsCapabilities(): PdnsCapabilities
    {
        return $this->capabilities ?? PdnsCapabilities::fromServerInfo(['version' => '4.9.0']);
    }
}
