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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PoweradminInstall\TwigEnvironmentInitializer;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class TwigEnvironmentInitializerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('PoweradminInstall\TwigEnvironmentInitializer')) {
            $this->markTestSkipped('Install folder not present - TwigEnvironmentInitializer not available');
        }
    }

    private function applyFilter(string $value): string
    {
        $filter = TwigEnvironmentInitializer::phpStrFilter();
        return ($filter->getCallable())($value);
    }

    public function testFilterPassesThroughNormalCharacters(): void
    {
        $this->assertSame('hello world', $this->applyFilter('hello world'));
        $this->assertSame('admin@example.net', $this->applyFilter('admin@example.net'));
        $this->assertSame('', $this->applyFilter(''));
    }

    public function testFilterEscapesSingleQuote(): void
    {
        // 'It\'s'
        $this->assertSame("It\\'s", $this->applyFilter("It's"));
    }

    public function testFilterEscapesBackslash(): void
    {
        // C:\Users\foo -> C:\\Users\\foo
        $this->assertSame('C:\\\\Users\\\\foo', $this->applyFilter('C:\\Users\\foo'));
    }

    public function testFilterEscapesBackslashAndQuoteTogether(): void
    {
        // input: \'  (1 backslash + 1 quote)
        // expected escaped: \\\'  (2 backslashes + escaped quote)
        $this->assertSame("\\\\\\'", $this->applyFilter("\\'"));
    }

    public function testFilterCoercesNullToEmptyString(): void
    {
        $filter = TwigEnvironmentInitializer::phpStrFilter();
        $this->assertSame('', ($filter->getCallable())(null));
    }

    public function testFilterCoercesIntegerToString(): void
    {
        $filter = TwigEnvironmentInitializer::phpStrFilter();
        $this->assertSame('3306', ($filter->getCallable())(3306));
    }

    public function testFilterCollapsesArrayToEmptyString(): void
    {
        $filter = TwigEnvironmentInitializer::phpStrFilter();
        $this->assertSame('', ($filter->getCallable())(['unexpected', 'array']));
    }

    public function testFilterCollapsesObjectToEmptyString(): void
    {
        $filter = TwigEnvironmentInitializer::phpStrFilter();
        $this->assertSame('', ($filter->getCallable())(new \stdClass()));
    }

    /**
     * Round-trip: an escaped value embedded inside a PHP single-quoted string
     * literal must parse back to the original value. This is the property the
     * filter exists to enforce - without it, generated config files break on
     * legitimate inputs and admit code injection on hostile ones.
     */
    public function testRoundTripThroughEvaluatedPhpStringPreservesValue(): void
    {
        $samples = [
            "plain",
            "with spaces and 1234",
            "It's a value",
            "C:\\Program Files\\App",
            "double\\\\backslash",
            "edge: \\' both at once",
            "trailing backslash \\",
            "embedded\nnewline",
            "tab\there",
            "',exit;//",
            "'.system('id').'",
            "\\'+payload+'\\",
        ];

        foreach ($samples as $original) {
            $escaped = $this->applyFilter($original);
            $phpSource = "return '" . $escaped . "';";

            $roundTripped = eval($phpSource);

            $this->assertSame(
                $original,
                $roundTripped,
                'Round trip failed for input: ' . var_export($original, true)
            );
        }
    }

    public function testFilterIsRegisteredUnderExpectedName(): void
    {
        $loader = new ArrayLoader(['t' => "{{ value|php_str }}"]);
        $env = new Environment($loader);
        $env->addFilter(TwigEnvironmentInitializer::phpStrFilter());

        // Twig autoescape (HTML) runs after the filter and encodes the apostrophe
        // we just escaped. That is fine because the install template shows the
        // result inside <pre> (so it renders visually as `\'`) and the copy
        // button reads textContent, which resolves the entity back. Verify both
        // properties: the HTML source carries an entity, and the textContent-equivalent
        // matches the PHP-escaped form the operator must end up with.
        $htmlSource = $env->render('t', ['value' => "It's"]);
        $this->assertStringContainsString('&#039;', $htmlSource);

        $clipboardEquivalent = html_entity_decode($htmlSource, ENT_QUOTES | ENT_HTML5);
        $this->assertSame("It\\'s", $clipboardEquivalent);
    }

    /**
     * Full end-to-end check on the unsafe characters: render through Twig with
     * autoescape on, decode entities (simulating the copy button's textContent),
     * embed in a PHP single-quoted string literal, eval, and assert we recover
     * the original value.
     */
    public function testEndToEndCopyButtonRoundTripPreservesValue(): void
    {
        $loader = new ArrayLoader(['t' => "'{{ value|php_str }}'"]);
        $env = new Environment($loader);
        $env->addFilter(TwigEnvironmentInitializer::phpStrFilter());

        $samples = [
            "It's a tricky pass",
            "C:\\path\\name",
            "',exit;//",
            "'.system('id').'",
            "edge \\' both",
        ];

        foreach ($samples as $original) {
            $htmlSource = $env->render('t', ['value' => $original]);
            $clipboard = html_entity_decode($htmlSource, ENT_QUOTES | ENT_HTML5);
            $roundTripped = eval("return $clipboard;");

            $this->assertSame(
                $original,
                $roundTripped,
                'End-to-end round trip failed for input: ' . var_export($original, true)
            );
        }
    }
}
