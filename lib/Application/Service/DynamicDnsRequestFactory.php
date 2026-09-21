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

namespace Poweradmin\Application\Service;

use Closure;
use PDO;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Domain\Repository\DynamicDnsRepositoryInterface;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\DynamicDnsAuthenticationService;
use Poweradmin\Domain\Service\DynamicDnsUpdateService;
use Poweradmin\Domain\Service\DynamicDnsValidationService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\AuditLogWriter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the dyndns2 request value object and the update service that handles it.
 * Reading $_SERVER and the Symfony request lives here so the value object stays
 * a plain, dependency-free carrier of already-resolved values.
 */
class DynamicDnsRequestFactory
{
    /**
     * Wires the update service from the live database connection and configuration.
     * A controller passes its memoised AuditService; the dyndns2 script has none to share.
     */
    public static function createUpdateService(
        PDO $db,
        ConfigurationManager $config,
        DynamicDnsRepositoryInterface $repository,
        PermissionService $permissions,
        ?AuditService $auditService = null
    ): DynamicDnsUpdateService {
        $client = ClientContext::fromServer($_SERVER);

        return new DynamicDnsUpdateService(
            new DynamicDnsValidationService($config),
            new DynamicDnsAuthenticationService(
                $repository,
                UserAuthenticationService::fromConfig($config),
                new LoginAttemptService($db, $config)
            ),
            $repository,
            $auditService ?? new AuditService(new AuditLogWriter($db), $client, new UserContextService()),
            $client->ip,
            self::requiresApproval($config, $permissions)
        );
    }

    /**
     * Whether a DDNS user's changes to a zone would have to go through review.
     */
    private static function requiresApproval(ConfigurationManager $config, PermissionService $permissions): Closure
    {
        return static fn(int $userId, int $zoneId): bool => ChangeApprovalPolicy::mode(
            (bool)$config->get('approval', 'enabled', false),
            (bool)$config->get('approval', 'require_review_for_all', false),
            $permissions->getEditPermissionLevelForZone($userId, $zoneId),
            $permissions->getChangeRequestPermissionLevelForZone($userId, $zoneId),
            $permissions->userOwnsZone($userId, $zoneId)
        ) === ChangeApprovalPolicy::MODE_REQUEST;
    }

    public static function fromHttpRequest(Request $request): DynamicDnsRequest
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? $request->query->get('username', '');
        $password = $_SERVER['PHP_AUTH_PW'] ?? $request->query->get('password', '');
        $hostname = $request->query->get('hostname', '');
        $ipv4 = $request->query->get('myip') ?? $request->query->get('ip', '');
        $ipv6 = $request->query->get('myip6') ?? $request->query->get('ip6', '');
        $dualstackUpdate = $request->query->get('dualstack_update') === '1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        [$ipv4, $ipv6] = self::routeAddressFamilies($ipv4, $ipv6);

        if ($ipv4 === 'whatismyip' || $ipv6 === 'whatismyip') {
            $clientIp = ClientContext::fromServer($_SERVER)->ip;
            $ipValidator = new IPAddressValidator();

            if ($ipv4 === 'whatismyip') {
                $ipv4 = $ipValidator->isValidIPv4($clientIp) ? $clientIp : '';
            }

            if ($ipv6 === 'whatismyip') {
                $ipv6 = $ipValidator->isValidIPv6($clientIp) ? $clientIp : '';
            }
        }

        return new DynamicDnsRequest(
            $username,
            $password,
            $hostname,
            $ipv4,
            $ipv6,
            $dualstackUpdate,
            $userAgent
        );
    }

    /**
     * Route each address in `myip` to the slot matching its family. The standard dyndns2
     * `myip` parameter carries either family, and ddclient 3.11+ sends both as one
     * comma-separated list - moving the whole value to the v6 slot discarded every IPv4
     * address, which under `dualstack_update` then deleted the existing A records.
     *
     * An explicit `myip6` stays authoritative: v6 values in `myip` are dropped rather than
     * merged into it.
     *
     * @return array{0: string, 1: string} the IPv4 and IPv6 slots
     */
    private static function routeAddressFamilies(string $ipv4, string $ipv6): array
    {
        if ($ipv4 === '' || $ipv4 === 'whatismyip') {
            return [$ipv4, $ipv6];
        }

        $ipv4Parts = [];
        $ipv6Parts = [];
        foreach (explode(',', $ipv4) as $address) {
            $address = trim($address);
            if ($address === '') {
                continue;
            }
            if (str_contains($address, ':')) {
                $ipv6Parts[] = $address;
            } else {
                $ipv4Parts[] = $address;
            }
        }

        return [implode(',', $ipv4Parts), $ipv6 === '' ? implode(',', $ipv6Parts) : $ipv6];
    }
}
