<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Controller;

use Poweradmin\Application\Controller\ForgotPasswordController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\LoggerInterface;

/**
 * Test double that skips BaseController's constructor, which opens a database
 * connection, and captures rendering instead of running Twig.
 */
class TestableForgotPasswordController extends ForgotPasswordController
{
    /** @var array<int, array{0: string, 1: array}> */
    public array $rendered = [];

    public function __construct()
    {
    }

    public function render(string $template, array $params): void
    {
        $this->rendered[] = [$template, $params];
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setConfig(ConfigurationManager $config): void
    {
        $this->config = $config;
    }
}
