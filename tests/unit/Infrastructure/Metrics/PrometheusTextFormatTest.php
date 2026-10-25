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

namespace Poweradmin\Tests\Unit\Infrastructure\Metrics;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Metrics\PrometheusTextFormat;

#[CoversClass(PrometheusTextFormat::class)]
class PrometheusTextFormatTest extends TestCase
{
    public function testRendersHelpTypeAndSamples(): void
    {
        $text = PrometheusTextFormat::render([
            [
                'name' => 'poweradmin_zones',
                'type' => 'gauge',
                'help' => 'Zones by type.',
                'samples' => [
                    ['labels' => ['type' => 'master'], 'value' => 3],
                    ['labels' => ['type' => 'native'], 'value' => 1],
                ],
            ],
            [
                'name' => 'poweradmin_metrics_collect_duration_seconds',
                'type' => 'gauge',
                'help' => 'Time spent.',
                'samples' => [['value' => 0.25]],
            ],
        ]);

        $this->assertSame(
            "# HELP poweradmin_zones Zones by type.\n"
            . "# TYPE poweradmin_zones gauge\n"
            . "poweradmin_zones{type=\"master\"} 3\n"
            . "poweradmin_zones{type=\"native\"} 1\n"
            . "# HELP poweradmin_metrics_collect_duration_seconds Time spent.\n"
            . "# TYPE poweradmin_metrics_collect_duration_seconds gauge\n"
            . "poweradmin_metrics_collect_duration_seconds 0.25\n",
            $text
        );
    }

    public function testEscapesLabelValuesAndHelp(): void
    {
        $text = PrometheusTextFormat::render([[
            'name' => 'x',
            'type' => 'gauge',
            'help' => "line\\one\nline two",
            'samples' => [['labels' => ['v' => "a\"b\\c\nd"], 'value' => 1]],
        ]]);

        $this->assertStringContainsString('# HELP x line\\\\one\\nline two', $text);
        $this->assertStringContainsString('x{v="a\\"b\\\\c\\nd"} 1', $text);
    }

    public function testFormatsSpecialFloats(): void
    {
        $text = PrometheusTextFormat::render([[
            'name' => 'x',
            'type' => 'gauge',
            'help' => 'h',
            'samples' => [['value' => 100.0], ['value' => NAN], ['value' => INF], ['value' => -INF]],
        ]]);

        $this->assertStringContainsString("x 100\nx NaN\nx +Inf\nx -Inf\n", $text);
    }

    public function testRejectsInvalidNamesAndTypes(): void
    {
        $invalid = [
            ['name' => '1bad', 'type' => 'gauge', 'help' => '', 'samples' => []],
            ['name' => 'ok', 'type' => 'histogram', 'help' => '', 'samples' => []],
            ['name' => 'ok', 'type' => 'gauge', 'help' => '', 'samples' => [['labels' => ['bad-label' => 'x'], 'value' => 1]]],
        ];
        foreach ($invalid as $family) {
            try {
                PrometheusTextFormat::render([$family]);
                $this->fail('Expected an exception for ' . json_encode($family));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
