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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\BatchPtrRecordController;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\UserContextService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The forward zone named in the batch PTR form is read or written when either option is set,
 * so the caller must be able to edit it; a missing zone answers like a foreign one.
 */
class BatchPtrRecordControllerAccessTest extends TestCase
{
    private const REFUSAL = 'You do not have the permission to add records to this zone.';

    private mixed $savedPost = null;
    private mixed $savedSession = null;

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
        $this->savedSession = $_SESSION ?? null;
        $_SESSION['userid'] = 100;
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        $_SESSION = $this->savedSession ?? [];
    }

    public function testForwardRecordCreationOnAMissingForwardZoneIsRefused(): void
    {
        $_POST = ['domain' => 'missing.example', 'create_forward_records' => 'on'];

        $this->assertSame(self::REFUSAL, $this->refusal());
    }

    public function testMatchingModeOnAMissingForwardZoneIsRefused(): void
    {
        $_POST = ['domain' => 'Missing.Example ', 'only_matching_records' => 'on'];

        $this->assertSame(self::REFUSAL, $this->refusal());
    }

    public function testTheZoneIsNotLookedUpWhenNeitherOptionIsSet(): void
    {
        $_POST = ['domain' => 'missing.example'];

        $this->assertNull($this->refusal(false));
    }

    public function testTheZoneNameIsLookedUpAsLowercasePunycode(): void
    {
        $_POST = ['domain' => ' Example.COM ', 'create_forward_records' => 'on'];

        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->expects($this->once())->method('getDomainIdByName')->with('example.com')->willReturn(null);

        $this->assertSame(self::REFUSAL, $this->refusal(true, $dnsRecord));
    }

    private function refusal(bool $expectLookup = true, ?DnsRecord $dnsRecord = null): ?string
    {
        if ($dnsRecord === null) {
            $dnsRecord = $this->createMock(DnsRecord::class);
            $dnsRecord->expects($expectLookup ? $this->once() : $this->never())->method('getDomainIdByName')->willReturn(null);
        }

        $controller = (new ReflectionClass(new class ([]) extends BatchPtrRecordController {
            public function __construct(array $request)
            {
                $this->requestData = $request;
            }

            public function checkCondition(bool $condition, string $errorMessage): void
            {
                if ($condition) {
                    throw new RuntimeException($errorMessage);
                }
            }
        }))->newInstanceWithoutConstructor();

        (new ReflectionProperty(BatchPtrRecordController::class, 'dnsRecord'))->setValue($controller, $dnsRecord);
        (new ReflectionProperty(BatchPtrRecordController::class, 'userContextService'))->setValue($controller, new UserContextService());

        try {
            (new ReflectionMethod(BatchPtrRecordController::class, 'checkForwardZoneAccess'))->invoke($controller);
        } catch (RuntimeException $halt) {
            return $halt->getMessage();
        }

        return null;
    }
}
