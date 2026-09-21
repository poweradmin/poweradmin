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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\BaseController;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * BaseController validates the CSRF token on every POST unless a controller
 * overrides requiresCsrfValidation(). Every opt-out must have a documented
 * substitute (flow token, OIDC state, SAML assertion, API key auth) — this
 * inventory fails when a new controller opts out without being reviewed here.
 */
class CsrfOptOutInventoryTest extends TestCase
{
    /**
     * Controllers allowed to override requiresCsrfValidation(), each with a
     * substitute protection. Add to this list only with a matching substitute.
     */
    private const ALLOWED_OPT_OUTS = [
        // Session-less or flow-token protected web controllers
        'Poweradmin\Application\Controller\Auth\LoginController',
        'Poweradmin\Application\Controller\Auth\MfaVerifyController',
        'Poweradmin\Application\Controller\Auth\ForgotPasswordController',
        'Poweradmin\Application\Controller\Auth\ForgotUsernameController',
        'Poweradmin\Application\Controller\Auth\ResetPasswordController',
        'Poweradmin\Application\Controller\Auth\OidcCallbackController',
        'Poweradmin\Application\Controller\Auth\SamlCallbackController',
        'Poweradmin\Application\Controller\System\NotFoundController',
        // API controllers authenticate per request (API key / Basic / X-CSRF-Token header)
        'Poweradmin\Application\Controller\Api\AbstractApiController',
        'Poweradmin\Application\Controller\Api\DocsController',
        'Poweradmin\Application\Controller\Api\Docs\JsonController',
    ];

    public function testOnlyReviewedControllersOptOutOfCsrfValidation(): void
    {
        $unexpected = [];

        foreach ($this->controllerClasses() as $class) {
            $reflection = new ReflectionClass($class);
            $declaring = $reflection->getMethod('requiresCsrfValidation')->getDeclaringClass()->getName();
            if ($declaring === BaseController::class) {
                continue;
            }
            if (!in_array($declaring, self::ALLOWED_OPT_OUTS, true)) {
                $unexpected[$class] = $declaring;
            }
        }

        $this->assertSame(
            [],
            $unexpected,
            'Controllers opt out of the base CSRF check without being reviewed in ALLOWED_OPT_OUTS: '
            . json_encode($unexpected)
        );
    }

    /** @return string[] fully-qualified names of all concrete controller classes */
    private function controllerClasses(): array
    {
        $root = dirname(__DIR__, 4) . '/lib/Application/Controller';
        $classes = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'Poweradmin\\Application\\Controller\\' . str_replace('/', '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }
            if ((new ReflectionClass($class))->isSubclassOf(BaseController::class)) {
                $classes[] = $class;
            }
        }

        $this->assertGreaterThan(80, count($classes), 'Controller discovery looks broken');
        return $classes;
    }
}
