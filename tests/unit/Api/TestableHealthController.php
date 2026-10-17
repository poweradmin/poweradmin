<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Api;

use Poweradmin\Application\Controller\Api\HealthController;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Test double stubbing the probes so the payload can be asserted on without a
 * database or a PowerDNS server. Leaving either result null runs the real check:
 * pdns_api needs no server while it is unconfigured, and an unknown db.type makes
 * the database probe fail without touching the network.
 */
class TestableHealthController extends HealthController
{
    public ?string $databaseResult = 'ok';
    public ?string $pdnsResult = null;
    public ?string $submitted = null;
    public string $cachePath = '';
    public int $databaseChecks = 0;

    public function __construct(private readonly ConfigurationInterface $stubConfig)
    {
    }

    protected function config(): ConfigurationInterface
    {
        return $this->stubConfig;
    }

    protected function checkDatabase(): string
    {
        $this->databaseChecks++;
        return $this->databaseResult ?? parent::checkDatabase();
    }

    protected function checkPowerdnsApi(): string
    {
        return $this->pdnsResult ?? parent::checkPowerdnsApi();
    }

    protected function submittedToken(): ?string
    {
        return $this->submitted;
    }

    protected function cacheFile(): string
    {
        return $this->cachePath !== '' ? $this->cachePath : parent::cacheFile();
    }
}
