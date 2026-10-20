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

use Poweradmin\Application\Boot\BootContext;
use Poweradmin\Application\Boot\Kernel;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Backend\DnsBackendProviderFactory;
use Poweradmin\Infrastructure\Database\BackendModeMarker;
use Poweradmin\Infrastructure\Database\DatabaseCredentialMapper;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Database\PdnsTable;
use Poweradmin\Domain\Database\TableNameService;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Session\ArraySession;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

/**
 * Unauthenticated readiness probe reporting whether Poweradmin can reach its
 * own dependencies. Disabled by default.
 *
 * Deliberately does not extend BaseController: that constructor connects to the
 * database unconditionally (Kernel::controllerEnvironment()), so a dead database
 * would escape as a generic 500 instead of being reported here as a failed check.
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

    private ConfigurationInterface $config;

    private ?LoggerInterface $logger = null;

    /**
     * @param BootContext $context The booted context; the router passes it to every controller outside BaseController
     */
    public function __construct(BootContext $context)
    {
        $this->config = $context->config;
        $this->logger = $context->logger;
    }

    /**
     * Overridable so tests can substitute settings without a boot context.
     */
    protected function config(): ConfigurationInterface
    {
        return $this->config;
    }

    /**
     * The kernel's logger, built from configuration alone so it works while the
     * database is down. Honours logging.type, which is 'null' by default: an
     * operator who turned diagnostic logging off does not get a line per scrape.
     */
    protected function logger(): LoggerInterface
    {
        // A test double that skipped the constructor gets the same logger built once
        return $this->logger ??= Logger::fromConfig($this->config());
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

        if (!$this->tokenAccepted()) {
            return new JsonResponse([
                'error' => true,
                'message' => 'Forbidden',
            ], 403, self::NO_STORE);
        }

        $cacheSeconds = max(0, (int) $this->config()->get('health', 'cache_seconds', 0));
        $checks = $cacheSeconds > 0 ? $this->readCachedChecks($cacheSeconds) : null;
        if ($checks === null) {
            $checks = [
                'database' => $this->checkDatabase(),
                'pdns_api' => $this->checkPowerdnsApi(),
            ];
            if ($cacheSeconds > 0) {
                $this->writeCachedChecks($checks);
            }
        }

        $healthy = !in_array(self::STATUS_DOWN, $checks, true);

        return new JsonResponse([
            'status' => $healthy ? self::STATUS_OK : self::STATUS_ERROR,
            'checks' => $checks,
        ], $healthy ? 200 : 503, self::NO_STORE);
    }

    /**
     * With health.token set, the probe answers only callers sending it in X-Health-Token,
     * so an anonymous request makes no database or PowerDNS call.
     */
    private function tokenAccepted(): bool
    {
        $expected = (string) $this->config()->get('health', 'token', '');
        if ($expected === '') {
            return true;
        }

        $given = $this->submittedToken();

        return $given !== null && hash_equals($expected, $given);
    }

    protected function submittedToken(): ?string
    {
        $token = (new Request())->getServerParam('HTTP_X_HEALTH_TOKEN');

        return is_string($token) ? $token : null;
    }

    /**
     * Per-installation file in the system temp directory, so concurrent probes share one result.
     */
    protected function cacheFile(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'poweradmin-health-' . hash('sha256', __DIR__) . '.json';
    }

    /**
     * @return array<string, string>|null
     */
    private function readCachedChecks(int $cacheSeconds): ?array
    {
        $file = $this->cacheFile();
        // Ignore a file or symlink another account planted in a shared temp directory. getmyuid() is the
        // script owner, not the process, so it is only the fallback without the posix extension.
        $processUid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (@is_link($file) || !@is_file($file) || @fileowner($file) !== $processUid) {
            return null;
        }

        $cached = json_decode((string) @file_get_contents($file), true);
        if (!is_array($cached) || !is_int($cached['at'] ?? null) || !is_array($cached['checks'] ?? null)) {
            return null;
        }
        if ($cached['at'] > time() || time() - $cached['at'] >= $cacheSeconds) {
            return null;
        }

        $checks = [];
        foreach (['database', 'pdns_api'] as $name) {
            $status = $cached['checks'][$name] ?? null;
            if (!in_array($status, [self::STATUS_OK, self::STATUS_DOWN, self::STATUS_SKIPPED], true)) {
                return null;
            }
            $checks[$name] = $status;
        }

        return $checks;
    }

    /**
     * Best effort: a temp directory that cannot be written only means the next probe runs the checks.
     *
     * @param array<string, string> $checks
     */
    private function writeCachedChecks(array $checks): void
    {
        $file = $this->cacheFile();
        $temporary = @tempnam(dirname($file), 'poweradmin-health-');
        if ($temporary === false) {
            return;
        }
        if (@file_put_contents($temporary, json_encode(['at' => time(), 'checks' => $checks])) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
        }
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

            $db = Kernel::connect($credentials);
            $db->query('SELECT 1');

            // Every other request is refused then, so the instance must not look ready
            if (!DnsBackendProviderFactory::isApiBackend($this->config())) {
                $domainsTable = (new TableNameService($this->config()))->getTable(PdnsTable::DOMAINS);
                $refusal = BackendModeMarker::sqlModeRefusal($db, $domainsTable);
                if ($refusal !== null) {
                    $this->logger()->error('Health check: ' . $refusal . ' ' . BackendModeMarker::OVERRIDE_HINT);
                    return self::STATUS_DOWN;
                }
            }

            return self::STATUS_OK;
        } catch (Throwable $e) {
            // The message embeds the DSN, so it goes to the log and never to the caller.
            $this->logger()->error('Health check: database unreachable: ' . $e->getMessage());

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
            // Short timeout (HttpClient retries a GET once, so 10s could hold this ~20s);
            // a probe keeps no session, so the client records into a throwaway one
            $client = DnsBackendProviderFactory::createApiClient(
                $this->config(),
                new ArraySession(),
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
            $this->logger()->error('Health check: PowerDNS API unreachable: ' . $e->getMessage());

            return self::STATUS_DOWN;
        }
    }
}
