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

namespace Poweradmin\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Web\BadgeTwigExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;

/**
 * The API backend pages the forward zone list even under "Show all", so the page
 * links must stay whenever the page holds fewer zones than the filter matched.
 */
class ForwardZoneListPaginationRenderTest extends TestCase
{
    private const PAGE_LINKS = '<nav id="zone-page-links"></nav>';

    public static function themeProvider(): array
    {
        return [
            'default theme' => ['default'],
            'modern theme' => ['modern'],
        ];
    }

    #[DataProvider('themeProvider')]
    public function testShowAllKeepsThePageLinksWhenThePageHoldsFewerZonesThanMatched(string $theme): void
    {
        $this->assertStringContainsString(self::PAGE_LINKS, $this->render($theme, 'all', 5));
    }

    #[DataProvider('themeProvider')]
    public function testShowAllHidesThePageLinksWhenEveryZoneIsOnThePage(string $theme): void
    {
        $this->assertStringNotContainsString(self::PAGE_LINKS, $this->render($theme, 'all', 2));
    }

    #[DataProvider('themeProvider')]
    public function testLetterFilterAlwaysShowsThePageLinks(string $theme): void
    {
        $this->assertStringContainsString(self::PAGE_LINKS, $this->render($theme, 'a', 2));
    }

    private function render(string $theme, string $letterStart, int $matched): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/' . $theme));
        $twig->addExtension(new TranslationExtension(new Translator('en')));
        $twig->addExtension(new BadgeTwigExtension());
        if (class_exists(IntlExtension::class)) {
            $twig->addExtension(new IntlExtension());
        }

        return $twig->render('list_forward_zones.html', [
            'zones' => [
                ['id' => 1, 'name' => 'a.example', 'type' => 'MASTER', 'owners' => [], 'full_names' => []],
                ['id' => 2, 'name' => 'b.example', 'type' => 'MASTER', 'owners' => [], 'full_names' => []],
            ],
            'count_zones_all_letterstart' => $matched,
            'count_zones_view' => $matched,
            'letter_start' => $letterStart,
            'pagination' => self::PAGE_LINKS,
        ]);
    }
}
