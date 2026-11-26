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

namespace Poweradmin\Tests\Unit\Module\DnsWizard\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Module\DnsWizard\Controller\Api\DnsWizardApiController;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

/**
 * A body of the wrong JSON shape is a client error, not a TypeError from the wizard.
 */
#[CoversClass(DnsWizardApiController::class)]
class DnsWizardApiControllerInputShapeTest extends SeamControllerTestCase
{
    /** @return array<string, array{string, string}> */
    public static function badBodyProvider(): array
    {
        $cases = [];
        foreach (['validateWizardData', 'previewWizardRecord', 'generateRecord'] as $method) {
            $cases["$method form data string"] = [$method, '{"type":"DMARC","formData":"x"}'];
            $cases["$method type array"] = [$method, '{"type":["DMARC"],"formData":{}}'];
            $cases["$method body scalar"] = [$method, '"x"'];
        }
        $cases['parse record data string'] = ['parseExistingRecord', '{"type":"DMARC","content":"v=DMARC1","recordData":"x"}'];
        $cases['parse content array'] = ['parseExistingRecord', '{"type":"DMARC","content":["v=DMARC1"]}'];
        $cases['parse type number'] = ['parseExistingRecord', '{"type":5,"content":"v=DMARC1"}'];

        return $cases;
    }

    #[DataProvider('badBodyProvider')]
    public function testAMalformedBodyIsRefusedWith400(string $method, string $content): void
    {
        $controller = (new \ReflectionClass(DnsWizardApiController::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(BaseController::class, '__construct'))
            ->invoke($controller, [], true, $this->environment($this->configure([])));
        (new ReflectionProperty($controller, 'request'))
            ->setValue($controller, Request::create('/api/internal/dns-wizard', 'POST', [], [], [], [], $content));

        $response = (new ReflectionMethod($controller, $method))->invoke($controller);

        $this->assertSame(400, $response->getStatusCode());
    }
}
