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

namespace Poweradmin\Tests\Unit\Application\Http;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\RequestValidator;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Covers the request validation semantics extracted from BaseController:
 * rule-to-constraint conversion, empty-value filtering, and the tolerant
 * extra/missing-field behavior web forms rely on.
 */
class RequestValidatorTest extends TestCase
{
    public function testRequiredRulePassesAndFails(): void
    {
        $validator = new RequestValidator();
        $validator->setRules(['required' => ['name']]);

        $this->assertSame(0, $validator->validate(['name' => 'example.com'])->count());
        $this->assertGreaterThan(0, $validator->validate(['name' => null])->count());
        // Long-standing tolerant behavior: an absent field passes even a
        // "required" rule (allowMissingFields), only blank present values fail
        $this->assertSame(0, $validator->validate([])->count());
    }

    public function testIntegerRuleAcceptsNumericStringsOnly(): void
    {
        $validator = new RequestValidator();
        $validator->setRules(['integer' => ['zone_id']]);

        $this->assertSame(0, $validator->validate(['zone_id' => '42'])->count());
        $this->assertGreaterThan(0, $validator->validate(['zone_id' => 'abc'])->count());
    }

    public function testEmptyStringValuesAreFilteredBeforeTypeChecks(): void
    {
        $validator = new RequestValidator();
        $validator->setRules(['integer' => ['zone_id']]);

        // '' would fail the numeric type check; the filter turns it into a
        // missing field, which the tolerant collection allows
        $this->assertSame(0, $validator->validate(['zone_id' => ''])->count());
    }

    public function testExtraFieldsAreAllowed(): void
    {
        $validator = new RequestValidator();
        $validator->setConstraints(['name' => new Assert\NotBlank()]);

        $this->assertSame(0, $validator->validate(['name' => 'x', 'unrelated' => 'y'])->count());
    }

    public function testFirstErrorMessageReturnsConfiguredMessage(): void
    {
        $validator = new RequestValidator();
        $validator->setConstraints([
            'name' => new Assert\NotBlank(['message' => 'name is missing']),
        ]);

        $this->assertSame('name is missing', $validator->firstErrorMessage(['name' => null]));
        $this->assertNull($validator->firstErrorMessage(['name' => 'ok']));
    }
}
