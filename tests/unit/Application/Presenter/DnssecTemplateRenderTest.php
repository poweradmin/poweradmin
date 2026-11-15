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

namespace Poweradmin\Tests\Unit\Application\Presenter;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Web\BadgeTwigExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;

/**
 * The key pages refuse while dnssec.enabled is off, so the DNSSEC overview must
 * not offer them, or the unsign button (the service refuses it too), then.
 */
class DnssecTemplateRenderTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/templates/default');
        $this->twig = new Environment($loader, ['strict_variables' => true]);
        $this->twig->addExtension(new TranslationExtension(new Translator('en')));
        $this->twig->addExtension(new BadgeTwigExtension());
        $this->twig->addExtension(new IntlExtension());
        $this->twig->addGlobal('base_url_prefix', '/pa');
        $this->twig->addGlobal('csrf_token', 'token');
    }

    private function render(bool $dnssecEnabled): string
    {
        return $this->twig->render('dnssec.html', [
            'domain_name' => 'example.com',
            'idn_zone_name' => '',
            'zone_display_name' => 'example.com',
            'domain_type' => 'MASTER',
            'keys' => [[11, 'ZSK', 'tag', 13, 256, true]],
            'pdnssec_use' => $dnssecEnabled,
            'record_count' => 3,
            'zone_id' => 42,
            'zone_template_id' => 0,
            'zone_templates' => [],
            'algorithms' => [13 => 'ECDSAP256SHA256'],
            'algorithm_names' => [13 => 'ECDSAP256SHA256'],
            'can_manage_dnssec' => true,
            'perm_edit' => 'all',
            'is_presigned' => false,
            'signed_serial' => null,
            'is_reverse_zone' => false,
            'pdns_caps' => new class {
                public bool $supportsPrivateKeyImport = true;
            },
        ]);
    }

    public function testKeyActionsAreOfferedWhileDnssecIsEnabled(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('/zones/42/dnssec/keys/add', $html);
        $this->assertStringContainsString('/zones/42/dnssec/keys/11/export', $html);
        $this->assertStringContainsString('/zones/42/dnssec/keys/import', $html);
        $this->assertStringContainsString('data-bs-target="#unsignZoneModal"', $html);
    }

    public function testKeyActionsAreHiddenWhileDnssecIsDisabled(): void
    {
        $html = $this->render(false);

        $this->assertStringNotContainsString('/dnssec/keys/', $html);
        $this->assertStringNotContainsString('data-bs-target="#unsignZoneModal"', $html);
        $this->assertStringContainsString('/zones/42/dnssec/ds-dnskey', $html);
    }
}
