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

namespace Poweradmin\Application\Boot;

use PDO;
use Poweradmin\Application\Module\ModuleRegistry;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Database\PdnsTable;
use Poweradmin\Domain\Database\TableNameService;
use Poweradmin\Domain\Enum\DnsBackendKind;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Port\SessionInterface;
use Poweradmin\Infrastructure\Database\BackendModeMarker;
use Poweradmin\Infrastructure\Database\DatabaseCredentialMapper;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What Kernel::boot() built for this process: the configuration, the logger,
 * the loaded module registry and, once something asks for it, the database.
 */
final class BootContext
{
    private ?PDO $db;
    private ?string $backendRefusal = null;
    private bool $backendChecked = false;

    /**
     * @param ModuleRegistry $moduleRegistry Already loaded; routes, templates and capabilities come from it
     * @param SessionInterface $session The process's one session, opened by Bootstrap
     * @param PDO|null $db An open connection, or null to open one from the configuration on first use
     */
    public function __construct(
        public readonly ConfigurationInterface $config,
        public readonly LoggerInterface $logger,
        public readonly ModuleRegistry $moduleRegistry,
        public readonly SessionInterface $session,
        ?PDO $db = null
    ) {
        $this->db = $db;
    }

    /**
     * The process's one database connection, opened from the configured
     * credentials the first time it is asked for.
     */
    public function database(): PDO
    {
        return $this->db ??= Kernel::connect(DatabaseCredentialMapper::mapCredentials($this->config));
    }

    /**
     * Why the configured backend may not use this database, or null; checked once per process.
     * The SQL backend refuses a database the API backend has written zone ids to (see BackendModeMarker).
     */
    public function backendRefusal(): ?string
    {
        if (!$this->backendChecked) {
            $this->backendChecked = true;
            if (!DnsBackendKind::fromConfig($this->config)->isApi()) {
                $domainsTable = (new TableNameService($this->config))->getTable(PdnsTable::DOMAINS);
                $this->backendRefusal = BackendModeMarker::sqlModeRefusal($this->database(), $domainsTable);
            }
        }

        return $this->backendRefusal;
    }

    /**
     * The per-request service graph over this context, acting as the given actor.
     *
     * @throws RuntimeException When the configured backend may not use the database
     */
    public function services(ActorInterface $actor): ControllerServiceFactory
    {
        $refusal = $this->backendRefusal();
        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        return new ControllerServiceFactory($this->database(), $this->config, $this->logger, $actor, $this->session);
    }
}
