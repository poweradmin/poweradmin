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

namespace Poweradmin\Tests\Unit\Infrastructure\Utility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\LanguageCode;

class LanguageCodeTest extends TestCase
{
    public function testGetByLocaleReturnsLanguageNameFromTwoLetterPrefix(): void
    {
        $this->assertEquals('English', LanguageCode::getByLocale('en_EN'));
        $this->assertEquals('German', LanguageCode::getByLocale('de_DE'));
        $this->assertEquals('Arabic', LanguageCode::getByLocale('ar_SA'));
    }

    public function testGetByLocaleReturnsRegionalNameForOverriddenLocales(): void
    {
        $this->assertEquals('Portuguese (Brazil)', LanguageCode::getByLocale('pt_BR'));
        $this->assertEquals('Portuguese (Portugal)', LanguageCode::getByLocale('pt_PT'));
        $this->assertEquals('Chinese (Simplified)', LanguageCode::getByLocale('zh_CN'));
        $this->assertEquals('Chinese (Traditional)', LanguageCode::getByLocale('zh_TW'));
    }

    public function testGetByLocaleReturnsNullForUnknownLocale(): void
    {
        $this->assertNull(LanguageCode::getByLocale('xx_YY'));
    }

    #[DataProvider('rtlLocales')]
    public function testIsRtlReturnsTrueForRtlLocales(string $locale): void
    {
        $this->assertTrue(LanguageCode::isRtl($locale));
    }

    public static function rtlLocales(): array
    {
        return [
            'Arabic'    => ['ar_SA'],
            'Farsi'     => ['fa_IR'],
            'Hebrew'    => ['he_IL'],
            'Urdu'      => ['ur_PK'],
            'Yiddish'   => ['yi_DE'],
            'short ar'  => ['ar'],
        ];
    }

    #[DataProvider('ltrLocales')]
    public function testIsRtlReturnsFalseForLtrLocales(string $locale): void
    {
        $this->assertFalse(LanguageCode::isRtl($locale));
    }

    public static function ltrLocales(): array
    {
        return [
            'English'   => ['en_EN'],
            'German'    => ['de_DE'],
            'French'    => ['fr_FR'],
            'Chinese'   => ['zh_CN'],
            'Japanese'  => ['ja_JP'],
            'Thai'      => ['th_TH'],
        ];
    }

    public function testTemplateVarsForLtrLocaleProducesLtrBundle(): void
    {
        $vars = LanguageCode::templateVars('en_EN');
        $this->assertFalse($vars['is_rtl']);
        $this->assertEquals('ltr', $vars['html_dir']);
        $this->assertEquals('en', $vars['html_lang']);
        $this->assertEquals('bootstrap.min.css', $vars['bootstrap_css']);
    }

    public function testTemplateVarsForRtlLocaleProducesRtlBundle(): void
    {
        $vars = LanguageCode::templateVars('ar_SA');
        $this->assertTrue($vars['is_rtl']);
        $this->assertEquals('rtl', $vars['html_dir']);
        $this->assertEquals('ar', $vars['html_lang']);
        $this->assertEquals('bootstrap.rtl.min.css', $vars['bootstrap_css']);
    }
}
