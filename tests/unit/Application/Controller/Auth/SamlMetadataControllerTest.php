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

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Controller\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\Auth\SamlMetadataController;
use Poweradmin\Application\Service\Auth\SamlConfigurationService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

#[CoversClass(SamlMetadataController::class)]
class SamlMetadataControllerTest extends SeamControllerTestCase
{
    public function testAMetadataFailureDoesNotEchoTheExceptionMessage(): void
    {
        $config = $this->configure(['saml' => [
            'enabled' => true,
            'providers' => ['idp' => ['entity_id' => 'https://idp.example', 'sso_url' => 'https://idp.example/sso']],
        ]]);

        $samlConfig = $this->createMock(SamlConfigurationService::class);
        $samlConfig->method('validatePermissionTemplateMapping')->willReturn([]);
        $samlConfig->method('describeProviderConfigError')->willReturn(null);
        $samlConfig->method('generateOneLoginSettings')->willThrowException(new \RuntimeException('private key at /etc/saml/sp.key unreadable'));
        $this->factory->method('samlConfigurationService')->willReturn($samlConfig);

        $this->query(['provider' => 'idp']);
        $controller = new SamlMetadataController([], $this->environment($config));

        ob_start();
        $controller->run();
        $output = (string)ob_get_clean();

        $this->assertSame('Error generating SAML metadata', $output);
    }
}
