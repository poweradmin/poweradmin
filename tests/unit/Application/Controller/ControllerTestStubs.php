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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PDO;
use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Validator\Validation;

/**
 * For anonymous test subclasses of a controller: skips BaseController's constructor
 * (it opens its own database connection and session) and turns the error pages,
 * which normally exit, into an exception the test can read.
 */
trait ControllerTestStubs
{
    public function __construct(array $request, PDO $db, ConfigurationManager $config)
    {
        $this->requestData = $request;
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        (new ReflectionProperty(BaseController::class, 'validator'))->setValue($this, Validation::createValidator());
    }

    public function showError(string $error, ?string $recordName = null): void
    {
        throw new RuntimeException($error);
    }

    public function checkCondition(bool $condition, string $errorMessage): void
    {
        if ($condition) {
            throw new RuntimeException($errorMessage);
        }
    }

    public function validateCsrfToken(): void
    {
    }
}
