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

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\SamlMetadataController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\SamlService;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * The SAML metadata endpoint is public, so a generation failure is logged and never echoed.
 */
class SamlMetadataControllerErrorTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testAGenerationFailureIsLoggedAndNotShownToTheCaller(): void
    {
        $_GET = ['provider' => 'idp'];

        $saml = $this->createMock(SamlService::class);
        $saml->method('isEnabled')->willReturn(true);
        $saml->method('getAvailableProviders')->willReturn(['idp' => []]);
        $saml->method('generateMetadata')->willThrowException(new RuntimeException('cert at /etc/poweradmin/saml.key unreadable'));

        $logFile = tempnam(sys_get_temp_dir(), 'samlmeta');
        ini_set('error_log', $logFile);

        $controller = (new ReflectionClass(SamlMetadataController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(SamlMetadataController::class, 'samlService'))->setValue($controller, $saml);
        (new ReflectionProperty(SamlMetadataController::class, 'httpRequest'))->setValue($controller, new Request());

        ob_start();
        $controller->run();
        $output = ob_get_clean();

        $this->assertSame('Error generating SAML metadata.', $output);
        $this->assertStringContainsString('saml.key', (string)file_get_contents($logFile));
        unlink($logFile);
    }
}
