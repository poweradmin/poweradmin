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

namespace Poweradmin\Tests\Unit\Application\Presenter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\RecordFormFieldPresenter;
use Poweradmin\Domain\Service\Validation\RecordField;

/**
 * Pins which form input each refusal DnsRecordValidationService and its shared
 * validators emit today ends up highlighting, so the field a validator names
 * explicitly can be checked against what the message heuristic used to pick.
 */
#[CoversClass(RecordFormFieldPresenter::class)]
class RecordFormFieldPresenterTest extends TestCase
{
    /**
     * Each row: the message, the field the validator attaches (null when it still
     * relies on the message), the input id the heuristic picks from the message
     * alone, and the input the form highlights now that TTL and name refusals are
     * named by their validators.
     *
     * @return array<string, array{string, RecordField|null, string, string}>
     */
    public static function validationMessages(): array
    {
        return [
            'zone missing' => ['Unable to find domain with the given ID.', null, 'content', 'content'],
            'cname exists for name' => ['This is not a valid record. There already exists a CNAME with this name.', RecordField::DUPLICATE, 'name-content-duplicate', 'name-content-duplicate'],
            'duplicate cname' => ['Multiple CNAME records with the same name are not allowed. This would create a DNS violation.', RecordField::NAME, 'content', 'name'],
            'cname beside other type' => ['A CNAME record cannot coexist with other record types for the same name. Found existing A record.', RecordField::NAME, 'content', 'name'],
            'other type beside cname' => ['This record conflicts with an existing CNAME record with the same name. A CNAME record cannot coexist with other record types.', RecordField::NAME, 'content', 'name'],
            'mx/srv priority' => ['Priority for MX/SRV records must be a number between 0 and 65535.', RecordField::PRIO, 'prio', 'prio'],
            'ns/mx to cname' => ['You can not point a NS or MX record to a CNAME record. Remove or rename the CNAME record first, or take another name.', RecordField::CONTENT, 'content', 'content'],
            'duplicate record' => ['A record with this hostname, type, and content already exists.', RecordField::DUPLICATE, 'name-content-duplicate', 'name-content-duplicate'],
            'default ttl' => ['Invalid value for default TTL. It must be a number between 0 and 2147483647.', RecordField::TTL, 'content', 'ttl'],
            'ttl not numeric' => ['Invalid value for TTL field. It must be numeric.', RecordField::TTL, 'content', 'ttl'],
            'ttl negative' => ['TTL value cannot be negative. It must be 0 or higher.', RecordField::TTL, 'content', 'ttl'],
            'ttl too large' => ['TTL value exceeds maximum allowed (2147483647). RFC 2181 limits it to a signed 32-bit integer.', RecordField::TTL, 'content', 'ttl'],
            'hostname too long' => ['The hostname is too long.', RecordField::NAME, 'content', 'name'],
            'single label' => ['Single-label hostnames are not allowed.', RecordField::NAME, 'content', 'name'],
            'bad tld' => ['You are using an invalid top level domain.', RecordField::NAME, 'content', 'name'],
            'too many slashes' => ['Given hostname has too many slashes.', RecordField::NAME, 'content', 'name'],
            'target hostname too long' => ['The hostname is too long.', null, 'content', 'content'],
            'ipv4' => ['This is not a valid IPv4 address.', RecordField::CONTENT, 'content', 'content'],
            'ipv6' => ['This is not a valid IPv6 address.', RecordField::CONTENT, 'content', 'content'],
            'a content' => ['Invalid IPv4 address format.', null, 'content', 'content'],
            'a priority' => ['Invalid value for priority field. A records must have priority value of 0.', null, 'content', 'content'],
            'cname not unique' => ['This is not a valid CNAME. There already exists a record with this name.', RecordField::DUPLICATE, 'name-content-duplicate', 'name-content-duplicate'],
            'cname is mx/ns target' => ['This is not a valid CNAME. Did you assign an MX or NS record to the record?', RecordField::NAME, 'content', 'name'],
            'apex cname' => ['Empty CNAME records are not allowed.', RecordField::NAME, 'content', 'name'],
            'mr name' => ['MR record name must be a valid domain name.', RecordField::NAME, 'content', 'name'],
            'cname target not fqdn' => ['CNAME target must be a fully qualified domain name (FQDN) with a valid top-level domain.', RecordField::CONTENT, 'content', 'content'],
        ];
    }

    #[DataProvider('validationMessages')]
    public function testHeuristicStillPicksTheSameField(string $message, ?RecordField $field, string $heuristic, string $highlighted): void
    {
        $this->assertSame($heuristic, RecordFormFieldPresenter::fieldForMessage($message));
    }

    #[DataProvider('validationMessages')]
    public function testNamedFieldDecidesTheHighlightedInput(string $message, ?RecordField $field, string $heuristic, string $highlighted): void
    {
        $this->assertSame($highlighted, RecordFormFieldPresenter::fieldId($field, $message));
    }

    public function testEveryFieldMapsToAFormInputId(): void
    {
        $this->assertSame('name', RecordFormFieldPresenter::idFor(RecordField::NAME));
        $this->assertSame('content', RecordFormFieldPresenter::idFor(RecordField::CONTENT));
        $this->assertSame('ttl', RecordFormFieldPresenter::idFor(RecordField::TTL));
        $this->assertSame('prio', RecordFormFieldPresenter::idFor(RecordField::PRIO));
        $this->assertSame('name-content-duplicate', RecordFormFieldPresenter::idFor(RecordField::DUPLICATE));
    }

    public function testNamedFieldWinsOverTheMessage(): void
    {
        $this->assertSame('ttl', RecordFormFieldPresenter::fieldId(RecordField::TTL, 'Invalid value for TTL field. It must be numeric.'));
    }
}
