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
     * Each row: the message, the field the validator now attaches (null when it
     * still relies on the message), and the input id the heuristic picked before.
     *
     * @return array<string, array{string, RecordField|null, string}>
     */
    public static function validationMessages(): array
    {
        return [
            'zone missing' => ['Unable to find domain with the given ID.', null, 'content'],
            'cname exists for name' => ['This is not a valid record. There already exists a CNAME with this name.', RecordField::DUPLICATE, 'name-content-duplicate'],
            'duplicate cname' => ['Multiple CNAME records with the same name are not allowed. This would create a DNS violation.', null, 'content'],
            'cname beside other type' => ['A CNAME record cannot coexist with other record types for the same name. Found existing A record.', null, 'content'],
            'other type beside cname' => ['This record conflicts with an existing CNAME record with the same name. A CNAME record cannot coexist with other record types.', null, 'content'],
            'mx/srv priority' => ['Priority for MX/SRV records must be a number between 0 and 65535.', RecordField::PRIO, 'prio'],
            'ns/mx to cname' => ['You can not point a NS or MX record to a CNAME record. Remove or rename the CNAME record first, or take another name.', RecordField::CONTENT, 'content'],
            'duplicate record' => ['A record with this hostname, type, and content already exists.', RecordField::DUPLICATE, 'name-content-duplicate'],
            'default ttl' => ['Invalid value for default TTL. It must be a number between 0 and 2147483647.', null, 'content'],
            'ttl not numeric' => ['Invalid value for TTL field. It must be numeric.', null, 'content'],
            'ttl negative' => ['TTL value cannot be negative. It must be 0 or higher.', null, 'content'],
            'ttl too large' => ['TTL value exceeds maximum allowed (2147483647). RFC 2181 limits it to a signed 32-bit integer.', null, 'content'],
            'hostname too long' => ['The hostname is too long.', null, 'content'],
            'single label' => ['Single-label hostnames are not allowed.', null, 'content'],
            'bad tld' => ['You are using an invalid top level domain.', null, 'content'],
            'too many slashes' => ['Given hostname has too many slashes.', null, 'content'],
            'ipv4' => ['This is not a valid IPv4 address.', RecordField::CONTENT, 'content'],
            'ipv6' => ['This is not a valid IPv6 address.', RecordField::CONTENT, 'content'],
            'a content' => ['Invalid IPv4 address format.', null, 'content'],
            'a priority' => ['Invalid value for priority field. A records must have priority value of 0.', null, 'content'],
            'cname not unique' => ['This is not a valid CNAME. There already exists a record with this name.', RecordField::DUPLICATE, 'name-content-duplicate'],
            'cname is mx/ns target' => ['This is not a valid CNAME. Did you assign an MX or NS record to the record?', null, 'content'],
            'apex cname' => ['Empty CNAME records are not allowed.', null, 'content'],
            'cname target not fqdn' => ['CNAME target must be a fully qualified domain name (FQDN) with a valid top-level domain.', RecordField::CONTENT, 'content'],
        ];
    }

    #[DataProvider('validationMessages')]
    public function testHeuristicStillPicksTheSameField(string $message, ?RecordField $field, string $expected): void
    {
        $this->assertSame($expected, RecordFormFieldPresenter::fieldForMessage($message));
    }

    #[DataProvider('validationMessages')]
    public function testNamedFieldHighlightsTheSameInputAsBefore(string $message, ?RecordField $field, string $expected): void
    {
        $this->assertSame($expected, RecordFormFieldPresenter::fieldId($field, $message));
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
