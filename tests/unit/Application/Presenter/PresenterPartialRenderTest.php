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
use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Presenter\ZoneStartingLettersPresenter;
use Poweradmin\Domain\Model\Pagination;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The Twig partials must render exactly what the presenters' string forms produce,
 * since theme forks may still read the `pagination` and `letters` variables.
 */
class PresenterPartialRenderTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/templates/default');
        $this->twig = new Environment($loader, ['strict_variables' => true]);
        $this->twig->addExtension(new TranslationExtension(new Translator('en')));
    }

    private function pagination(int $totalItems, int $currentPage, string $pattern = '/users/{PageNumber}', ?int $rowsPerPage = null): PaginationPresenter
    {
        return new PaginationPresenter(new Pagination($totalItems, 10, $currentPage), $pattern, $rowsPerPage);
    }

    public function testPaginationPartialMatchesPresentForFirstMiddleAndLastPage(): void
    {
        foreach ([[25, 1], [300, 15], [300, 30], [100, 6], [100, 2], [100, 9]] as [$total, $page]) {
            $presenter = $this->pagination($total, $page);
            $rendered = $this->twig->render('_partials/pagination.html', ['items' => $presenter->items()]);

            $this->assertSame($presenter->present(), $rendered, "page $page of " . ceil($total / 10));
        }
    }

    public function testPaginationPartialRendersNothingForASinglePage(): void
    {
        $this->assertSame('', $this->twig->render('_partials/pagination.html', ['items' => $this->pagination(10, 1)->items()]));
    }

    public function testPaginationPartialEscapesTheQuerySeparatorThatPresentLeavesRaw(): void
    {
        $presenter = $this->pagination(25, 2, '/zones/forward?start={PageNumber}', 50);
        $rendered = $this->twig->render('_partials/pagination.html', ['items' => $presenter->items()]);

        $this->assertStringContainsString('href="/zones/forward?start=3&amp;rows_per_page=50"', $rendered);
        $this->assertSame($presenter->present(), str_replace('&amp;', '&', $rendered));
    }

    public function testPaginationPartialLinksThroughTheJsHandlerWhenAsked(): void
    {
        $presenter = $this->pagination(300, 15, '');
        $rendered = $this->twig->render('_partials/pagination.html', ['items' => $presenter->items(), 'js_handler' => 'do_search_with_zones_page']);

        $this->assertStringContainsString('<li class="page-item"><a class="page-link" href="javascript:do_search_with_zones_page(14)">Previous</a></li>', $rendered);
        $this->assertStringContainsString('<li class="page-item active"><a class="page-link" href="javascript:do_search_with_zones_page(15)">15</a></li>', $rendered);
        $this->assertStringContainsString('<li class="page-item disabled"><span class="page-link">..</span></li><li class="page-item"><a class="page-link" href="javascript:do_search_with_zones_page(30)">30</a></li>', $rendered);
        $this->assertStringNotContainsString('href=""', $rendered);
    }

    public function testLettersPartialMatchesPresent(): void
    {
        $presenter = new ZoneStartingLettersPresenter();
        $cases = [
            [['1', 'a', 'c'], true, 'a', ''],
            [['a'], false, 'all', '/pa'],
            [['1'], true, '1', ''],
            [['a', 'ä', '<'], false, 'ä', ''],
        ];

        foreach ($cases as [$chars, $digits, $start, $prefix]) {
            $rendered = $this->twig->render('_partials/letters.html', ['items' => $presenter->items($chars, $digits, $start, $prefix)]);

            $this->assertSame($presenter->present($chars, $digits, $start, $prefix), $rendered, "letter $start");
        }
    }

    public function testLettersPartialEscapesTheQuerySeparatorThatPresentLeavesRaw(): void
    {
        $presenter = new ZoneStartingLettersPresenter();
        $rendered = $this->twig->render('_partials/letters.html', ['items' => $presenter->items(['b'], false, 'all', '', 25)]);

        $this->assertStringContainsString('href="/zones/forward?letter=b&amp;rows_per_page=25"', $rendered);
        $this->assertSame($presenter->present(['b'], false, 'all', '', 25), str_replace('&amp;', '&', $rendered));
    }
}
