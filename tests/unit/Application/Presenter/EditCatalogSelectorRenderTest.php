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
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Poweradmin\Infrastructure\Web\BadgeTwigExtension;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * The catalog selector on the zone edit page, rendered from the block in edit.html:
 * a user who may not leave the zone's catalog is offered "keep" and nothing else
 * is preselected.
 */
class EditCatalogSelectorRenderTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../../templates/default';

    private Environment $twig;

    protected function setUp(): void
    {
        $source = (string)file_get_contents(self::TEMPLATES . '/edit.html');
        $start = strpos($source, '{% if catalog_selector_view %}');
        $end = strpos($source, '<!-- DNSSEC -->');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        // The slice ends with the enclosing block's own endif, which is not part of the selector
        $block = rtrim(substr($source, $start, $end - $start));
        $block = substr($block, 0, (int)strrpos($block, '{% endif %}'));

        $loader = new ChainLoader([
            new ArrayLoader(['selector' => "{% import '_macros.html' as macros %}\n" . $block]),
            new FilesystemLoader(self::TEMPLATES),
        ]);
        $this->twig = new Environment($loader, ['strict_variables' => true]);
        $this->twig->addExtension(new TranslationExtension(new Translator('en')));
        $this->twig->addExtension(new BadgeTwigExtension());
        $this->twig->addExtension(new IntlExtension());
        $this->twig->addGlobal('base_url_prefix', '');
        $this->twig->addGlobal('csrf_token', 'token');
    }

    private function render(array $context): string
    {
        return $this->twig->render('selector', $context + [
            'catalog_selector_view' => true,
            'meta_edit' => true,
            'zone_id' => 42,
            'catalog_name' => 'cat.example',
            'catalog_producer_id' => 5,
            'catalog_locked' => false,
            'catalog_producers' => [['id' => 5, 'name' => 'cat.example'], ['id' => 6, 'name' => 'other.example']],
        ]);
    }

    public function testALockedCatalogOffersAndPreselectsKeepOnly(): void
    {
        $html = $this->render(['catalog_locked' => true]);

        $this->assertStringContainsString('<option value="keep" selected>', $html);
        $this->assertSame(1, substr_count($html, 'selected'));
    }

    public function testAManageableCatalogPreselectsItsProducerWithoutKeep(): void
    {
        $html = $this->render([]);

        $this->assertStringNotContainsString('value="keep"', $html);
        $this->assertStringContainsString('<option value="5" selected>', $html);
    }

    public function testACatalogWithoutALocalProducerStillOffersKeep(): void
    {
        $html = $this->render(['catalog_producer_id' => null]);

        $this->assertStringContainsString('<option value="keep" selected>', $html);
    }
}
