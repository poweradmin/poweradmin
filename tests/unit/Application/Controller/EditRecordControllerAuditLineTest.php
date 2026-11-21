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

/**
 * The single-record edit page builds its audit line inline, so the check reads the source:
 * new values come first (the line is cut at a length cap) and free text is encoded.
 */
class EditRecordControllerAuditLineTest extends TestCase
{
    public function testTheEditLineWritesNewValuesBeforeOldOnesAndEncodesFreeText(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/../lib/Application/Controller/EditRecordController.php');
        $start = strpos($source, "operation:edit_record'");
        $this->assertNotFalse($start);
        $block = substr($source, $start, 1600);

        $this->assertLessThan(strpos($block, 'old_record_type:%s'), strpos($block, ' record_type:%s'));
        $this->assertSame(6, substr_count($block, 'AuditLineValue::of('));
    }
}
