<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Api;

use Poweradmin\Application\Controller\Api\HealthController;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Test double stubbing the database probe so the payload can be asserted on
 * without one. Leaving pdnsResult null runs the real check, which needs no
 * server while pdns_api is unconfigured.
 */
class TestableHealthController extends HealthController
{
    public string $databaseResult = 'ok';
    public ?string $pdnsResult = null;

    public function __construct(private readonly ConfigurationInterface $stubConfig)
    {
    }

    protected function config(): ConfigurationInterface
    {
        return $this->stubConfig;
    }

    protected function checkDatabase(): string
    {
        return $this->databaseResult;
    }

    protected function checkPowerdnsApi(): string
    {
        return $this->pdnsResult ?? parent::checkPowerdnsApi();
    }
}
