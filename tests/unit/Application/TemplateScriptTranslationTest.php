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

use PHPUnit\Framework\TestCase;

/**
 * A {% trans %} block is emitted unescaped, so a translation with an apostrophe
 * breaks any JS string it sits in. Inside <script> use |trans|e('js').
 */
class TemplateScriptTranslationTest extends TestCase
{
    public function testNoTransBlockInsideScriptElements(): void
    {
        $offenders = [];
        $root = dirname(__DIR__, 3) . '/templates';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'html') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!preg_match_all('~<script\b[^>]*>(.*?)</script>~s', $source, $scripts)) {
                continue;
            }
            foreach ($scripts[1] as $body) {
                if (preg_match('~\{%-?\s*trans\b~', $body)) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                    break;
                }
            }
        }

        sort($offenders);
        $this->assertSame([], $offenders, 'Use {{ \'text\'|trans|e(\'js\') }} inside <script>');
    }
}
