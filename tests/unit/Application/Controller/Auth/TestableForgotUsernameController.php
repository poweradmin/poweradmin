<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Controller\Auth;

use Poweradmin\Application\Controller\Auth\ForgotUsernameController;
use Psr\Log\LoggerInterface;

/**
 * Builds the controller through the ControllerEnvironment seam and lets a test
 * swap in the logger it wants to observe.
 */
class TestableForgotUsernameController extends ForgotUsernameController
{
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
