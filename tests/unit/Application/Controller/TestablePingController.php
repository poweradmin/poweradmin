<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Controller;

use Poweradmin\Application\Controller\PingController;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Test double supplying settings without going through the config singleton.
 */
class TestablePingController extends PingController
{
    public function __construct(private readonly ConfigurationInterface $stubConfig)
    {
    }

    protected function config(): ConfigurationInterface
    {
        return $this->stubConfig;
    }
}
