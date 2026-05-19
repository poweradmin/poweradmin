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

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Poweradmin\Infrastructure\Utility\CsvFormulaEscaper;

class CsvFormulaEscaperTest extends TestCase
{
    public static function triggerCharacters(): array
    {
        return [
            'equals'          => ['='],
            'plus'            => ['+'],
            'minus'           => ['-'],
            'at'              => ['@'],
            'tab'             => ["\t"],
            'carriage return' => ["\r"],
            'line feed'       => ["\n"],
        ];
    }

    #[DataProvider('triggerCharacters')]
    public function testValueStartingWithTriggerGetsQuotePrefix(string $trigger): void
    {
        $value = $trigger . 'abc';
        $this->assertSame("'" . $value, CsvFormulaEscaper::escape($value));
    }

    #[DataProvider('triggerCharacters')]
    public function testTriggerAfterLeadingSpacesIsStillEscaped(string $trigger): void
    {
        // tab/CR/LF would be consumed by ltrim if spaces were stripped greedily;
        // they are still escaped because they remain the first character.
        $value = '   ' . $trigger . 'abc';
        $this->assertSame("'" . $value, CsvFormulaEscaper::escape($value));
    }

    public function testPlainStringsAreUnchanged(): void
    {
        $this->assertSame('admin', CsvFormulaEscaper::escape('admin'));
        $this->assertSame('user@example.com', CsvFormulaEscaper::escape('user@example.com'));
        $this->assertSame('example.com.', CsvFormulaEscaper::escape('example.com.'));
        $this->assertSame('192.0.2.1', CsvFormulaEscaper::escape('192.0.2.1'));
        $this->assertSame(' leading space ok', CsvFormulaEscaper::escape(' leading space ok'));
    }

    public function testEmptyStringIsUnchanged(): void
    {
        $this->assertSame('', CsvFormulaEscaper::escape(''));
    }

    public function testWhitespaceOnlyStringIsUnchanged(): void
    {
        $this->assertSame('   ', CsvFormulaEscaper::escape('   '));
    }

    public function testNonStringScalarsArePassedThrough(): void
    {
        $this->assertSame(42, CsvFormulaEscaper::escape(42));
        $this->assertSame(3.14, CsvFormulaEscaper::escape(3.14));
        $this->assertSame(true, CsvFormulaEscaper::escape(true));
        $this->assertSame(null, CsvFormulaEscaper::escape(null));
    }

    public function testEscapeRowAppliesElementWise(): void
    {
        $row = ['admin', '=abc', 'note', '@abc', 'plain'];
        $expected = ['admin', "'=abc", 'note', "'@abc", 'plain'];

        $this->assertSame($expected, CsvFormulaEscaper::escapeRow($row));
    }

    public function testEscapeRowPreservesKeys(): void
    {
        $row = ['username' => '=abc', 'email' => 'a@b.test'];
        $expected = ['username' => "'=abc", 'email' => 'a@b.test'];

        $this->assertSame($expected, CsvFormulaEscaper::escapeRow($row));
    }

    public function testEscapeRowPassesNonStringsThrough(): void
    {
        $row = ['id' => 5, 'active' => true, 'name' => '=abc', 'comment' => null];
        $expected = ['id' => 5, 'active' => true, 'name' => "'=abc", 'comment' => null];

        $this->assertSame($expected, CsvFormulaEscaper::escapeRow($row));
    }

    public function testEscapedValueSurvivesFputcsvRoundTrip(): void
    {
        $row = CsvFormulaEscaper::escapeRow(['=abc', 'plain']);
        $stream = fopen('php://memory', 'r+');
        fputcsv($stream, $row);
        rewind($stream);
        $written = stream_get_contents($stream);
        fclose($stream);

        // The single-quote prefix must survive fputcsv encoding so the
        // importing application treats the cell as text, not a formula.
        $this->assertStringContainsString("'=abc", $written);
        $this->assertStringContainsString('plain', $written);
    }
}
