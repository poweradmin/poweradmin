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

namespace Poweradmin\Application\Controller\Api;

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Domain\Service\DatabaseCredentialMapper;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

/**
 * Unauthenticated readiness probe reporting whether Poweradmin can reach its
 * own dependencies. Disabled by default.
 *
 * Deliberately does not extend BaseController: that constructor connects to the
 * database unconditionally (AppInitializer), so a dead database would escape as
 * a generic 500 instead of being reported here as a failed check.
 *
 * The body carries no version, host, driver or error text. Anyone can call this,
 * so a check either passed or it did not.
 */
class HealthController
{
    private const STATUS_OK = 'ok';
    private const STATUS_DOWN = 'down';
    private const STATUS_SKIPPED = 'skipped';
    private const STATUS_ERROR = 'error';

    private const NO_STORE = ['Cache-Control' => 'no-store'];

    private ?ConfigurationInterface $config = null;

    /**
     * Resolved lazily so tests can substitute settings without a constructor
     * argument the router would have to supply.
     */
    protected function config(): ConfigurationInterface
    {
        if ($this->config === null) {
            $manager = ConfigurationManager::getInstance();
            $manager->initialize();
            $this->config = $manager;
        }

        return $this->config;
    }

    public function run(): void
    {
        $this->buildResponse()->send();
    }

    public function buildResponse(): JsonResponse
    {
        if (!$this->config()->get('health', 'enabled', false)) {
            return new JsonResponse([
                'error' => true,
                'message' => 'Not Found',
            ], 404, self::NO_STORE);
        }

        $checks = [
            'database' => $this->checkDatabase(),
            'pdns_api' => $this->checkPowerdnsApi(),
        ];

        $healthy = !in_array(self::STATUS_DOWN, $checks, true);

        return new JsonResponse([
            'status' => $healthy ? self::STATUS_OK : self::STATUS_ERROR,
            'checks' => $checks,
        ], $healthy ? 200 : 503, self::NO_STORE);
    }

    /**
     * Opening the connection is the check; the trailing query catches a socket
     * that connected but cannot serve statements.
     */
    protected function checkDatabase(): string
    {
        try {
            $credentials = DatabaseCredentialMapper::mapCredentials($this->config());
            // Bound the connect: a host that drops packets would otherwise hold this
            // unauthenticated request for the OS TCP timeout, around 30 seconds.
            $credentials['db_connect_timeout'] = (int) $this->config()->get('health', 'db_timeout', 2);

            $db = (new DatabaseService(new PDODatabaseConnection()))->connect($credentials);
            $db->query('SELECT 1');

            return self::STATUS_OK;
        } catch (Throwable $e) {
            // The message embeds the DSN, so it goes to the log and never to the caller.
            error_log('Health check: database unreachable: ' . $e->getMessage());

            return self::STATUS_DOWN;
        }
    }

    /**
     * Reports 'skipped' when no PowerDNS API is configured, which is normal for
     * database-backend installs and never fails the overall status.
     */
    protected function checkPowerdnsApi(): string
    {
        try {
            // Short timeout, not the interactive pdns_api.timeout: HttpClient retries a
            // GET once, so a 10s default could hold this unauthenticated request ~20s.
            $client = DnsBackendProviderFactory::createApiClient(
                $this->config(),
                null,
                (int) $this->config()->get('health', 'pdns_timeout', 2)
            );

            if ($client === null) {
                // Unconfigured is only benign on the database backend. With dns.backend
                // set to api it means no zone operation can work, so it is a failure.
                return DnsBackendProviderFactory::isApiBackend($this->config())
                    ? self::STATUS_DOWN
                    : self::STATUS_SKIPPED;
            }

            // getServerInfo() swallows ApiErrorException and returns [], so an empty
            // result is the only failure signal it offers.
            return $client->getServerInfo() === [] ? self::STATUS_DOWN : self::STATUS_OK;
        } catch (Throwable $e) {
            error_log('Health check: PowerDNS API unreachable: ' . $e->getMessage());

            return self::STATUS_DOWN;
        }
    }
}
