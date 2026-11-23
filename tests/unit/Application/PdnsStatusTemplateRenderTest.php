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
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Statistics the PowerDNS API reports in an unexpected shape must not break the status page.
 */
class PdnsStatusTemplateRenderTest extends TestCase
{
    public static function themeProvider(): array
    {
        return ['default theme' => ['default'], 'modern theme' => ['modern']];
    }

    #[DataProvider('themeProvider')]
    public function testOddMetricValuesRenderWithoutAnError(string $theme): void
    {
        $html = $this->render($theme, [
            'udp-queries' => ['not', 'a', 'number'],
            'tcp-queries' => 'many',
            'packetcache-hit' => ['a' => 1],
            'packetcache-miss' => '',
            'latency' => ['x'],
            'user-msec' => ['x'],
            'sys-msec' => ['x'],
        ]);

        $this->assertStringContainsString('UDP Queries', $html);
    }

    #[DataProvider('themeProvider')]
    public function testNumericMetricsAreStillAbbreviated(string $theme): void
    {
        $html = $this->render($theme, ['udp-queries' => '2500000', 'tcp-queries' => 1500, 'packetcache-hit' => '7', 'latency' => '120']);

        $this->assertStringContainsString('2.5M', $html);
        $this->assertStringContainsString('1.5K', $html);
        $this->assertStringContainsString('120', $html);
    }

    private function render(string $theme, array $metrics): string
    {
        $twig = new Environment(new FilesystemLoader([dirname(__DIR__, 3) . '/templates/' . $theme, dirname(__DIR__, 3) . '/templates/default']), ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension(new Translator('en')));
        $twig->addExtension(new IntlExtension());
        $twig->addFunction(new TwigFunction('autoprimaries_label', static fn(string $key): string => $key));
        $twig->addGlobal('base_url_prefix', '');
        $twig->addGlobal('csrf_token', 'token');

        return $twig->render('pdns_status.html', [
            'pdns_api_enabled' => true,
            'server_status' => [
                'running' => true,
                'configured' => true,
                'display_name' => 'PowerDNS',
                'server_name' => 'localhost',
                'daemon_type' => 'authoritative',
                'version' => '4.9.4',
                'metrics' => $metrics,
                'metric_categories' => [],
            ],
            'slave_status' => [],
        ]);
    }
}
