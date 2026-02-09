<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

/**
 * Tests for CSV export functionality
 *
 * These tests verify that CSV generation works correctly with PHP 8.4+
 * where the fputcsv $escape parameter must be explicitly provided.
 *
 * @see https://github.com/poweradmin/poweradmin/issues/980
 */
class CsvExportTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'csv_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    /**
     * Test that fputcsv with explicit empty escape parameter produces valid CSV
     */
    public function testFputcsvWithEmptyEscapeProducesValidCsv(): void
    {
        $output = fopen($this->tempFile, 'w');

        $header = ['Name', 'Type', 'Content', 'Priority', 'TTL', 'Disabled'];
        fputcsv($output, $header, ',', '"', '\\');

        $row = ['example.com', 'A', '192.168.1.1', '0', '3600', 'No'];
        fputcsv($output, $row, ',', '"', '\\');

        fclose($output);

        $content = file_get_contents($this->tempFile);

        $this->assertStringContainsString('Name,Type,Content,Priority,TTL,Disabled', $content);
        $this->assertStringContainsString('example.com,A,192.168.1.1,0,3600,No', $content);
    }

    /**
     * Test that CSV export handles special characters correctly
     */
    public function testCsvExportHandlesSpecialCharacters(): void
    {
        $output = fopen($this->tempFile, 'w');

        $header = ['Name', 'Type', 'Content'];
        fputcsv($output, $header, ',', '"', '\\');

        // Test with quotes in content
        $row = ['example.com', 'TXT', 'v=spf1 include:"_spf.google.com" ~all'];
        fputcsv($output, $row, ',', '"', '\\');

        fclose($output);

        $content = file_get_contents($this->tempFile);

        $this->assertStringContainsString('Name,Type,Content', $content);
        // Quotes should be doubled in CSV format
        $this->assertStringContainsString('""_spf.google.com""', $content);
    }

    /**
     * Test that CSV export handles commas in content correctly
     */
    public function testCsvExportHandlesCommasInContent(): void
    {
        $output = fopen($this->tempFile, 'w');

        $header = ['Name', 'Content'];
        fputcsv($output, $header, ',', '"', '\\');

        $row = ['example.com', 'value1, value2, value3'];
        fputcsv($output, $row, ',', '"', '\\');

        fclose($output);

        $content = file_get_contents($this->tempFile);

        // Content with commas should be quoted
        $this->assertStringContainsString('"value1, value2, value3"', $content);
    }

    /**
     * Test that CSV export handles newlines in content correctly
     */
    public function testCsvExportHandlesNewlinesInContent(): void
    {
        $output = fopen($this->tempFile, 'w');

        $header = ['Name', 'Comment'];
        fputcsv($output, $header, ',', '"', '\\');

        $row = ['example.com', "line1\nline2"];
        fputcsv($output, $row, ',', '"', '\\');

        fclose($output);

        $content = file_get_contents($this->tempFile);

        // Content with newlines should be quoted
        $this->assertStringContainsString('"line1', $content);
    }

    /**
     * Test that CSV export handles empty values correctly
     */
    public function testCsvExportHandlesEmptyValues(): void
    {
        $output = fopen($this->tempFile, 'w');

        $header = ['Name', 'Type', 'Comment'];
        fputcsv($output, $header, ',', '"', '\\');

        $row = ['example.com', 'A', ''];
        fputcsv($output, $row, ',', '"', '\\');

        fclose($output);

        $content = file_get_contents($this->tempFile);

        $this->assertStringContainsString('example.com,A,', $content);
    }

    /**
     * Test that CSV export handles UTF-8 characters correctly
     */
    public function testCsvExportHandlesUtf8Characters(): void
    {
        $output = fopen($this->tempFile, 'w');

        // Add BOM for UTF-8 (as done in EditController)
        fputs($output, "\xEF\xBB\xBF");

        $header = ['Name', 'Comment'];
        fputcsv($output, $header, ',', '"', '\\');

        $row = ['example.com', 'Ümläuts and émojis 🎉'];
        fputcsv($output, $row, ',', '"', '\\');

        fclose($output);

        $content = file_get_contents($this->tempFile);

        // Check BOM is present
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Ümläuts', $content);
    }

    /**
     * Test that CSV can be read back correctly after writing
     */
    public function testCsvRoundTrip(): void
    {
        // Write CSV
        $output = fopen($this->tempFile, 'w');

        $originalData = [
            ['Name', 'Type', 'Content', 'Priority', 'TTL', 'Disabled'],
            ['example.com', 'A', '192.168.1.1', '0', '3600', 'No'],
            ['mail.example.com', 'MX', 'mail.example.com', '10', '3600', 'No'],
            ['example.com', 'TXT', 'v=spf1 include:"_spf.google.com" ~all', '0', '3600', 'No'],
        ];

        foreach ($originalData as $row) {
            fputcsv($output, $row, ',', '"', '\\');
        }

        fclose($output);

        // Read CSV back
        $input = fopen($this->tempFile, 'r');
        $readData = [];

        while (($row = fgetcsv($input, 0, ',', '"', '\\')) !== false) {
            $readData[] = $row;
        }

        fclose($input);

        $this->assertCount(4, $readData);
        $this->assertEquals($originalData[0], $readData[0]);
        $this->assertEquals($originalData[1], $readData[1]);
        $this->assertEquals($originalData[2], $readData[2]);
        $this->assertEquals($originalData[3], $readData[3]);
    }

    /**
     * Test DNS record data similar to actual export
     */
    public function testDnsRecordExport(): void
    {
        $output = fopen($this->tempFile, 'w');

        // Add BOM for UTF-8
        fputs($output, "\xEF\xBB\xBF");

        // Header as used in EditController
        $header = ['Name', 'Type', 'Content', 'Priority', 'TTL', 'Disabled'];
        fputcsv($output, $header, ',', '"', '\\');

        // Simulate records array as returned by getRecordsFromDomainId
        $records = [
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com hostmaster.example.com 2024010101 10800 3600 604800 3600', 'prio' => 0, 'ttl' => 3600, 'disabled' => false],
            ['name' => 'example.com', 'type' => 'NS', 'content' => 'ns1.example.com', 'prio' => 0, 'ttl' => 3600, 'disabled' => false],
            ['name' => 'example.com', 'type' => 'A', 'content' => '192.168.1.1', 'prio' => 0, 'ttl' => 3600, 'disabled' => false],
            ['name' => 'www.example.com', 'type' => 'CNAME', 'content' => 'example.com', 'prio' => 0, 'ttl' => 3600, 'disabled' => false],
            ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'prio' => 10, 'ttl' => 3600, 'disabled' => false],
            ['name' => 'example.com', 'type' => 'TXT', 'content' => 'v=spf1 include:_spf.google.com ~all', 'prio' => 0, 'ttl' => 3600, 'disabled' => true],
        ];

        foreach ($records as $record) {
            $row = [
                $record['name'],
                $record['type'],
                $record['content'],
                $record['prio'],
                $record['ttl'],
                $record['disabled'] ? 'Yes' : 'No'
            ];
            fputcsv($output, $row, ',', '"', '\\');
        }

        fclose($output);

        $content = file_get_contents($this->tempFile);

        // Verify no PHP warnings or errors in output
        $this->assertStringNotContainsString('<b>Deprecated</b>', $content);
        $this->assertStringNotContainsString('<b>Warning</b>', $content);
        $this->assertStringNotContainsString('<br />', $content);

        // Verify structure
        $this->assertStringContainsString('Name,Type,Content,Priority,TTL,Disabled', $content);
        $this->assertStringContainsString('example.com,SOA,', $content);
        $this->assertStringContainsString('example.com,NS,ns1.example.com', $content);
        $this->assertStringContainsString('example.com,A,192.168.1.1', $content);
        $this->assertStringContainsString('www.example.com,CNAME,example.com', $content);
        $this->assertStringContainsString('example.com,MX,mail.example.com,10', $content);
        $this->assertStringContainsString(',Yes', $content); // Disabled record
    }
}
