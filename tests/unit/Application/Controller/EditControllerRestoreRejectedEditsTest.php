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
 */

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditController;
use ReflectionClass;

/**
 * Tests for EditController::restoreRejectedEdits(), which puts the rows of a
 * submission rejected as stale back into the freshly read zone listing so the
 * operator does not lose their edits along with the warning.
 */
class EditControllerRestoreRejectedEditsTest extends TestCase
{
    private ReflectionClass $controllerReflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controllerReflection = new ReflectionClass(EditController::class);
    }

    public function testSubmittedValuesReplaceTheStoredOnesOnTheMatchingRow(): void
    {
        $records = [$this->storedRow(11, 'www', 'A', '192.0.2.1', 3600)];

        $dropped = $this->restore($records, [
            11 => $this->submittedRow(11, 'www', 'A', '192.0.2.99', '7200'),
        ]);

        $this->assertSame([], $dropped);
        $this->assertSame('192.0.2.99', $records[0]['content']);
        $this->assertSame('7200', $records[0]['ttl']);
        $this->assertTrue($records[0]['unsaved_edit']);
    }

    public function testStoredSummaryNamesTheValuesTheZoneStillHolds(): void
    {
        $records = [$this->storedRow(11, 'www', 'A', '192.0.2.1', 3600)];

        $this->restore($records, [
            11 => $this->submittedRow(11, 'www', 'A', '192.0.2.99', '7200'),
        ]);

        $this->assertStringContainsString('192.0.2.1', $records[0]['stored_summary']);
        $this->assertStringContainsString('3600', $records[0]['stored_summary']);
        // The name was submitted unchanged, so it is not worth reporting.
        $this->assertStringNotContainsString('www', $records[0]['stored_summary']);
    }

    public function testARowMatchingTheZoneIsNotTreatedAsAnUnsavedEdit(): void
    {
        $records = [$this->storedRow(11, 'www', 'A', '192.0.2.1', 3600)];

        $this->restore($records, [
            11 => $this->submittedRow(11, 'www', 'A', '192.0.2.1', '3600'),
        ]);

        // The submitted TTL is a string against a stored integer, which must not count.
        $this->assertSame('', $records[0]['stored_summary']);
        $this->assertFalse($records[0]['unsaved_edit']);
    }

    public function testARowLockedSinceTheFormWasRenderedKeepsTheStoredValues(): void
    {
        $stored = $this->storedRow(11, 'example.com', 'NS', 'ns1.example.com', 3600);
        $stored['record_locked'] = true;
        $records = [$stored];

        $dropped = $this->restore($records, [
            11 => $this->submittedRow(11, 'example.com', 'NS', 'ns2.example.com', '3600'),
        ]);

        $this->assertCount(1, $dropped);
        $this->assertStringContainsString('ns2.example.com', $dropped[0]);
        $this->assertSame('ns1.example.com', $records[0]['content']);
        $this->assertFalse($records[0]['unsaved_edit']);
    }

    public function testAChangedPriorityIsReported(): void
    {
        $stored = $this->storedRow(11, 'mail', 'MX', 'mx1.example.com', 3600);
        $stored['prio'] = 20;
        $records = [$stored];

        $submitted = $this->submittedRow(11, 'mail', 'MX', 'mx2.example.com', '3600');
        $submitted['prio'] = '10';
        $this->restore($records, [11 => $submitted]);

        $this->assertSame('10', $records[0]['prio']);
        $this->assertStringContainsString('20', $records[0]['stored_summary']);
    }

    public function testAnEmptyPriorityIsNotReportedAgainstAStoredZero(): void
    {
        $records = [$this->storedRow(11, 'www', 'A', '192.0.2.1', 3600)];

        $submitted = $this->submittedRow(11, 'www', 'A', '192.0.2.1', '3600');
        $submitted['prio'] = '';
        $this->restore($records, [11 => $submitted]);

        $this->assertSame('', $records[0]['stored_summary']);
    }

    public function testDisabledCheckboxIsRestoredFromTheSubmission(): void
    {
        $records = [$this->storedRow(11, 'www', 'A', '192.0.2.1', 3600)];

        $submitted = $this->submittedRow(11, 'www', 'A', '192.0.2.1', '3600');
        $submitted['disabled'] = 'on';
        $this->restore($records, [11 => $submitted]);

        $this->assertSame(1, $records[0]['disabled']);
        $this->assertStringContainsString('Disabled', $records[0]['stored_summary']);
    }

    public function testAnUncheckedDisabledBoxIsRestoredAsZero(): void
    {
        $stored = $this->storedRow(11, 'www', 'A', '192.0.2.1', 3600);
        $stored['disabled'] = 1;
        $records = [$stored];

        $this->restore($records, [
            11 => $this->submittedRow(11, 'www', 'A', '192.0.2.1', '3600'),
        ]);

        $this->assertSame(0, $records[0]['disabled']);
    }

    public function testRowsAbsentFromTheSubmissionKeepTheirStoredValues(): void
    {
        $records = [
            $this->storedRow(11, 'www', 'A', '192.0.2.1', 3600),
            $this->storedRow(12, 'mail', 'A', '192.0.2.2', 3600),
        ];

        $this->restore($records, [
            11 => $this->submittedRow(11, 'www', 'A', '192.0.2.99', '3600'),
        ]);

        $this->assertSame('192.0.2.2', $records[1]['content']);
        $this->assertFalse($records[1]['unsaved_edit']);
        $this->assertSame('', $records[1]['stored_summary']);
    }

    public function testASubmittedRowThatLeftTheListingIsReportedWithWhatWasTyped(): void
    {
        $records = [$this->storedRow(11, 'www', 'A', '192.0.2.1', 3600)];

        $dropped = $this->restore($records, [
            12 => $this->submittedRow(12, 'mail', 'A', '192.0.2.2', '3600'),
        ]);

        $this->assertCount(1, $dropped);
        $this->assertStringContainsString('mail', $dropped[0]);
        $this->assertStringContainsString('192.0.2.2', $dropped[0]);
        $this->assertStringContainsString('3600', $dropped[0]);
    }

    public function testTheSubmittedTypeIsRestoredAndTheStoredOneReported(): void
    {
        $records = [$this->storedRow(11, 'www', 'AAAA', '2001:db8::1', 3600)];

        $this->restore($records, [
            11 => $this->submittedRow(11, 'www', 'A', '192.0.2.99', '3600'),
        ]);

        $this->assertSame('A', $records[0]['type']);
        $this->assertStringContainsString('AAAA', $records[0]['stored_summary']);
    }

    public function testATruncatedRowIsLeftAsTheZoneHasIt(): void
    {
        $records = [$this->storedRow(11, 'www', 'A', '192.0.2.1', 3600)];

        $submitted = $this->submittedRow(11, 'www', 'A', '192.0.2.99', '7200');
        unset($submitted['_complete'], $submitted['ttl'], $submitted['comment']);
        $dropped = $this->restore($records, [11 => $submitted]);

        $this->assertSame([], $dropped);
        $this->assertSame('192.0.2.1', $records[0]['content']);
        $this->assertFalse($records[0]['unsaved_edit']);
    }

    public function testNothingHappensWithoutARejectedSubmission(): void
    {
        $records = [$this->storedRow(11, 'www', 'A', '192.0.2.1', 3600)];

        $this->assertSame([], $this->restore($records, []));
        $this->assertSame('192.0.2.1', $records[0]['content']);
        $this->assertFalse($records[0]['unsaved_edit']);
    }

    private function storedRow(int $id, string $name, string $type, string $content, int $ttl): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'editable_name' => $name,
            'display_name' => $name,
            'type' => $type,
            'content' => $content,
            'prio' => 0,
            'ttl' => $ttl,
            'comment' => '',
            'disabled' => 0,
            'record_locked' => false,
            'unsaved_edit' => false,
            'stored_summary' => '',
        ];
    }

    private function submittedRow(int $rid, string $name, string $type, string $content, string $ttl): array
    {
        return [
            'rid' => (string)$rid,
            'zid' => '1',
            '_complete' => '1',
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'prio' => '0',
            'ttl' => $ttl,
            'comment' => '',
        ];
    }

    private function restore(array &$records, array $rejected): array
    {
        $controller = $this->controllerReflection->newInstanceWithoutConstructor();

        $rejectedProperty = $this->controllerReflection->getProperty('rejectedRecords');
        $rejectedProperty->setAccessible(true);
        $rejectedProperty->setValue($controller, $rejected);

        $method = $this->controllerReflection->getMethod('restoreRejectedEdits');
        $method->setAccessible(true);

        return $method->invokeArgs($controller, [&$records]);
    }
}
