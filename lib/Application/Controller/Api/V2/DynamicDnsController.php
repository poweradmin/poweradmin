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

namespace Poweradmin\Application\Controller\Api\V2;

use OpenApi\Attributes as OA;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DynamicDnsAuthenticationService;
use Poweradmin\Domain\Service\DynamicDnsUpdateService;
use Poweradmin\Domain\Service\DynamicDnsValidationService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\ApiDynamicDnsRepository;
use Poweradmin\Infrastructure\Repository\SqlDynamicDnsRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Symfony\Component\HttpFoundation\JsonResponse;

class DynamicDnsController extends PublicApiController
{
    private DynamicDnsUpdateService $updateService;
    private DynamicDnsValidationService $validationService;
    private ApiPermissionService $permissionService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $config = $this->getConfig();
        $tableNameService = new TableNameService($config);
        $recordsTable = $tableNameService->getTable(PdnsTable::RECORDS);
        $domainsTable = $tableNameService->getTable(PdnsTable::DOMAINS);

        $dnsRecord = new DnsRecord($this->db, $config);
        $backendProvider = DnsBackendProviderFactory::create($this->db, $config);

        $repository = $backendProvider->isApiBackend()
            ? new ApiDynamicDnsRepository($this->db, $dnsRecord, $backendProvider)
            : new SqlDynamicDnsRepository($this->db, $dnsRecord, $recordsTable, $domainsTable);

        $userAuthService = new UserAuthenticationService(
            $config->get('security', 'password_encryption', 'bcrypt'),
            $config->get('security', 'password_cost', 12)
        );

        $this->validationService = new DynamicDnsValidationService($config);
        $this->updateService = new DynamicDnsUpdateService(
            $this->validationService,
            new DynamicDnsAuthenticationService($repository, $userAuthService),
            $repository,
            new LegacyLogger($this->db),
            new IpAddressRetriever($_SERVER)
        );
        $this->permissionService = new ApiPermissionService($this->db);
    }

    public function run(): void
    {
        $response = match ($this->request->getMethod()) {
            'POST' => $this->updateRecord(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    #[OA\Post(
        path: '/v2/dynamic-dns',
        operationId: 'v2DynamicDnsUpdate',
        summary: 'Update A/AAAA records via dynamic DNS',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['dynamic-dns']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['hostname'],
            properties: [
                new OA\Property(property: 'hostname', type: 'string', example: 'host.example.com'),
                new OA\Property(property: 'ipv4', description: 'IPv4 address, or comma-separated list', type: 'string', example: '192.0.2.1'),
                new OA\Property(property: 'ipv6', description: 'IPv6 address, or comma-separated list', type: 'string', example: '2001:db8::1'),
                new OA\Property(property: 'dualstack', description: 'When true, clears the opposite address family if no value is supplied for it', type: 'boolean', example: false),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Dynamic DNS record updated or already current',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Dynamic DNS record updated'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'hostname', type: 'string', example: 'host.example.com'),
                        new OA\Property(property: 'zone_id', type: 'integer', example: 3),
                        new OA\Property(property: 'applied_ipv4', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'applied_ipv6', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'changed', type: 'boolean', example: true),
                    ],
                    type: 'object'
                ),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid hostname or IP payload')]
    #[OA\Response(response: 403, description: 'Authenticated user lacks DDNS permission')]
    #[OA\Response(response: 404, description: 'No owned zone contains this hostname')]
    private function updateRecord(): JsonResponse
    {
        if (!$this->userCanUseDdns($this->getAuthenticatedUserId())) {
            return $this->returnApiError('User does not have permission to update dynamic DNS records', 403);
        }

        $payload = $this->getJsonInput();
        if (!is_array($payload)) {
            return $this->returnApiError('Request body must be a JSON object or form data', 400);
        }

        $rawHostname = (string)($payload['hostname'] ?? '');
        $rawIpv4 = (string)($payload['ipv4'] ?? '');
        $rawIpv6 = (string)($payload['ipv6'] ?? '');
        // filter_var handles 0/1, true/false, "true"/"false", "yes"/"no" consistently.
        // A bare (bool) cast would treat the string "false" as true.
        $dualstack = filter_var($payload['dualstack'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($rawHostname === '' || ($rawIpv4 === '' && $rawIpv6 === '' && !$dualstack)) {
            return $this->returnApiError('hostname and at least one of ipv4 or ipv6 are required', 400);
        }

        try {
            $hostname = $this->validationService->createValidatedHostname($rawHostname);
            $ipList = $this->validationService->createValidatedIpList($rawIpv4, $rawIpv6);
        } catch (\InvalidArgumentException $e) {
            return $this->returnApiError($e->getMessage(), 400);
        }

        // The User entity is only used downstream to look up owned zones by ID, so the
        // password/LDAP fields are intentionally blank.
        $user = new User($this->getAuthenticatedUserId(), '', false);

        $result = $this->updateService->applyForUser(
            $user,
            $this->getAuthenticatedUsername(),
            $hostname,
            $ipList,
            $dualstack
        );

        return match ($result['status']) {
            'good' => $this->returnApiResponse([
                'hostname' => $hostname->getValue(),
                'zone_id' => $result['zone_id'],
                'applied_ipv4' => $result['applied_ipv4'],
                'applied_ipv6' => $result['applied_ipv6'],
                'changed' => $result['changed'],
            ], true, $result['changed'] ? 'Dynamic DNS record updated' : 'Records already match supplied addresses'),
            'nohost' => $this->returnApiError('Hostname is not contained in any zone the user owns', 404),
            '!yours' => $this->returnApiError('Update did not produce any change and no matching records exist', 409),
            default => $this->returnApiError('Failed to apply dynamic DNS update', 500),
        };
    }

    private function userCanUseDdns(int $userId): bool
    {
        // DDNS operates only on zones the caller owns, so require an own-zone edit grant.
        return $this->permissionService->userHasPermission($userId, 'zone_content_edit_own')
            || $this->permissionService->userHasPermission($userId, 'zone_content_edit_own_as_client');
    }
}
