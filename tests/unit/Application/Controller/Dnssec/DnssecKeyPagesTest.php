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

namespace Poweradmin\Tests\Unit\Application\Controller\Dnssec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Controller\Dnssec\DnssecAddKeyController;
use Poweradmin\Application\Controller\Dnssec\DnssecDeleteKeyController;
use Poweradmin\Application\Controller\Dnssec\DnssecEditKeyController;
use Poweradmin\Application\Controller\Dnssec\DnssecKeyImportController;
use Poweradmin\Application\Controller\Dnssec\DnssecToggleKeyController;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\DnssecKeyOutcome;
use Poweradmin\Domain\Service\Zone\DnssecKeyService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The web key pages over the shared key service: which message each outcome
 * shows, and that only a change that went through is audited.
 */
#[CoversClass(DnssecAddKeyController::class)]
#[CoversClass(DnssecDeleteKeyController::class)]
#[CoversClass(DnssecEditKeyController::class)]
#[CoversClass(DnssecToggleKeyController::class)]
class DnssecKeyPagesTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;
    private const KEY_ID = 3;
    private const OUTAGE = 'DNSSEC functionality is not available. Please check PowerDNS API configuration.';

    /** @var DnssecProviderInterface&MockObject */
    private DnssecProviderInterface $dnssec;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    /** @var CryptoKey[]|null Null when PowerDNS cannot be reached */
    private ?array $keys;

    private bool $enabled = true;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canViewZone')->willReturn(true);
        $permissions->method('canManageDnssecForZone')->willReturn(true);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('zoneIdExists')->willReturn(true);
        $domains->method('getDomainNameById')->willReturn('example.com');

        $this->dnssec = $this->createMock(DnssecProviderInterface::class);
        $this->dnssec->method('fetchZoneKeys')->willReturnCallback(fn(): ?array => $this->keys);
        $this->dnssec->method('isDnssecEnabled')->willReturnCallback(fn(): bool => $this->enabled);
        $this->audit = $this->createMock(AuditService::class);
        $this->keys = [new CryptoKey(self::KEY_ID, 'zsk', 256, 'ECDSAP256SHA256', true, '256 3 13 AAAA', [])];

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('dnssecProvider')->willReturn($this->dnssec);
        $this->factory->method('auditService')->willReturn($this->audit);
        $this->factory->method('dnssecKeyService')->willReturnCallback(fn(): DnssecKeyService => new DnssecKeyService($this->dnssec, $this->audit));
    }

    /**
     * @param class-string<BaseController> $class
     */
    private function page(string $class): BaseController
    {
        $params = in_array($class, [DnssecAddKeyController::class, DnssecKeyImportController::class], true)
            ? ['id' => (string)self::ZONE_ID]
            : ['zone_id' => (string)self::ZONE_ID, 'key_id' => (string)self::KEY_ID];

        return new $class($params + $this->requestData(), true, $this->environment($this->configure()));
    }

    private function haltOf(BaseController $controller): RequestHalted
    {
        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }

    // ---------------------------------------------------------------- toggle

    public function testToggleDeactivatesAnActiveKeyAndAudits(): void
    {
        $this->dnssec->expects($this->once())->method('deactivateZoneKey')->with('example.com', self::KEY_ID)->willReturn(true);
        $this->audit->expects($this->once())->method('logDnssecToggleKey')->with(self::ZONE_ID, 'example.com', self::KEY_ID, 'deactivate');
        $this->post([]);

        $halt = $this->haltOf($this->page(DnssecToggleKeyController::class));

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame([['success', 'Zone key has been successfully deactivated.']], $this->messagesFor('dnssec'));
    }

    public function testAFailedToggleSaysWhichWayItFailed(): void
    {
        $this->dnssec->method('deactivateZoneKey')->willReturn(false);
        $this->audit->expects($this->never())->method('logDnssecToggleKey');
        $this->post([]);

        $this->haltOf($this->page(DnssecToggleKeyController::class));

        $this->assertSame([['error', 'Failed to deactivate zone key.']], $this->messagesFor('dnssec'));
    }

    public function testTogglingAnUnknownKeySaysItIsGone(): void
    {
        $this->keys = [];
        $this->post([]);

        $this->assertSame('DNSSEC key not found or no longer exists.', $this->haltOf($this->page(DnssecToggleKeyController::class))->target);
    }

    // ---------------------------------------------------------------- edit and delete

    public function testEditShowsTheKey(): void
    {
        $this->page(DnssecEditKeyController::class)->run();

        $this->assertSame('dnssec_edit_key.html', $this->renderedTemplate());
        $this->assertSame(self::KEY_ID, $this->renderedParams()['key_info'][0]);
        $this->assertSame('ZSK', $this->renderedParams()['key_info'][1]);
        $this->assertTrue($this->renderedParams()['key_info'][5]);
    }

    public function testDeleteConfirmationShowsTheKey(): void
    {
        $this->dnssec->expects($this->never())->method('removeZoneKey');

        $this->page(DnssecDeleteKeyController::class)->run();

        $this->assertSame('dnssec_delete_key.html', $this->renderedTemplate());
        $this->assertSame(self::KEY_ID, $this->renderedParams()['key_id']);
        $this->assertSame(self::KEY_ID, $this->renderedParams()['key_info'][0]);
    }

    /** @return array<string, array{0: class-string<BaseController>}> */
    public static function keyPageProvider(): array
    {
        return ['edit' => [DnssecEditKeyController::class], 'delete' => [DnssecDeleteKeyController::class]];
    }

    /**
     * @param class-string<BaseController> $class
     */
    #[DataProvider('keyPageProvider')]
    public function testAnUnknownKeyIsRefused(string $class): void
    {
        $this->keys = [];

        $this->assertSame('Invalid or unexpected input given.', $this->haltOf($this->page($class))->target);
    }

    public function testDeleteRemovesTheKeyAndAudits(): void
    {
        $this->dnssec->expects($this->once())->method('removeZoneKey')->with('example.com', self::KEY_ID)->willReturn(true);
        $this->audit->expects($this->once())->method('logDnssecDeleteKey')->with(self::ZONE_ID, 'example.com', self::KEY_ID);
        $this->post([]);

        $halt = $this->haltOf($this->page(DnssecDeleteKeyController::class));

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame([['success', 'Zone key has been deleted successfully.']], $this->messagesFor('dnssec'));
    }

    public function testAFailedDeleteIsReportedAndNotAudited(): void
    {
        $this->dnssec->method('removeZoneKey')->willReturn(false);
        $this->audit->expects($this->never())->method('logDnssecDeleteKey');
        $this->post([]);

        $this->haltOf($this->page(DnssecDeleteKeyController::class));

        $this->assertSame([['error', 'Failed to delete the zone key.']], $this->messagesFor('dnssec'));
    }

    public function testDeletingAnUnknownKeyIsRefused(): void
    {
        $this->keys = [];
        $this->dnssec->expects($this->never())->method('removeZoneKey');
        $this->post([]);

        $this->assertSame('Invalid or unexpected input given.', $this->haltOf($this->page(DnssecDeleteKeyController::class))->target);
    }

    // ---------------------------------------------------------------- import

    private function serverVersion(string $version): void
    {
        $this->session->set('pdns_server_info', ['info' => ['version' => $version], 'fetched_at' => time()]);
    }

    private function importP256(string $algorithm): void
    {
        if (!$this->session->has('pdns_server_info')) {
            $this->serverVersion('5.1.4');
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $this->post(['key_type' => 'csk', 'algorithm' => $algorithm, 'private_key' => $pem]);
    }

    public function testAnImportedPemKeyReachesPowerDnsAsBindTextAndAuditsTheCreatedKey(): void
    {
        $this->dnssec->expects($this->once())->method('importZoneKeyFromPrivateKey')
            ->with('example.com', 'csk', $this->stringStartsWith("Private-key-format: v1.2\nAlgorithm: 13 (ECDSAP256SHA256)\nPrivateKey: "), false)
            ->willReturn(new CryptoKey(9, 'csk', 256, 'ECDSAP256SHA256', false, '257 3 13 AAAA'));
        $this->audit->expects($this->once())->method('logDnssecAddKey')->with(self::ZONE_ID, 'example.com', 'csk', '256', 'ecdsa256');
        $this->importP256('ecdsa256');

        $halt = $this->haltOf($this->page(DnssecKeyImportController::class));

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame([['success', 'Key imported successfully.']], $this->messagesFor('dnssec'));
    }

    public function testAServerThatCannotImportKeysRefusesBeforeReadingTheKey(): void
    {
        $this->serverVersion('4.0.9');
        $this->dnssec->expects($this->never())->method('importZoneKeyFromPrivateKey');
        $this->importP256('ecdsa256');

        $this->haltOf($this->page(DnssecKeyImportController::class));

        $this->assertSame([['error', 'Importing keys requires PowerDNS 4.1 or newer.']], $this->messagesFor('dnssec'));
    }

    public function testAKeyForAnotherAlgorithmNeverReachesPowerDns(): void
    {
        $this->dnssec->expects($this->never())->method('importZoneKeyFromPrivateKey');
        $this->audit->expects($this->never())->method('logDnssecAddKey');
        $this->importP256('ecdsa384');

        $this->haltOf($this->page(DnssecKeyImportController::class));

        $this->assertSame([['error', 'The private key does not match the selected algorithm.']], $this->messagesFor('dnssec'));
    }

    public function testTextThatIsNoKeyIsRefusedBeforePowerDns(): void
    {
        $this->dnssec->expects($this->never())->method('importZoneKeyFromPrivateKey');
        $this->serverVersion('5.1.4');
        $this->post(['key_type' => 'csk', 'algorithm' => 'ecdsa256', 'private_key' => 'not a key']);

        $this->haltOf($this->page(DnssecKeyImportController::class));

        $this->assertSame([['error', 'The private key could not be read. Paste a BIND private key or an unencrypted PEM key.']], $this->messagesFor('dnssec'));
    }

    public function testAKeyPowerDnsRejectsIsReportedAndNotAudited(): void
    {
        $this->dnssec->method('importZoneKeyFromPrivateKey')->willReturn(DnssecKeyOutcome::KEY_REJECTED);
        $this->audit->expects($this->never())->method('logDnssecAddKey');
        $this->importP256('ecdsa256');

        $this->haltOf($this->page(DnssecKeyImportController::class));

        $this->assertSame([['error', 'Failed to import the key. PowerDNS rejected it.']], $this->messagesFor('dnssec'));
    }

    public function testAnImportWhilePowerDnsIsUnreachableReportsTheOutage(): void
    {
        $this->keys = null;
        $this->dnssec->expects($this->never())->method('importZoneKeyFromPrivateKey');
        $this->importP256('ecdsa256');

        $halt = $this->haltOf($this->page(DnssecKeyImportController::class));

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame(self::OUTAGE, $halt->target);
    }

    // ---------------------------------------------------------------- add

    private function submitKey(string $algorithm, string $bits): void
    {
        $this->post(['key_type' => 'csk', 'algorithm' => $algorithm, 'bits' => $bits, 'submit' => '1']);
    }

    public function testAddCreatesAnInactiveKeyAndAudits(): void
    {
        $this->dnssec->expects($this->once())->method('createZoneKey')->with('example.com', 'csk', 256, 'ecdsa256', false)
            ->willReturn(new CryptoKey(9, 'csk', 256, 'ECDSAP256SHA256'));
        $this->audit->expects($this->once())->method('logDnssecAddKey')->with(self::ZONE_ID, 'example.com', 'csk', '256', 'ecdsa256');
        $this->submitKey('ecdsa256', '256');

        $halt = $this->haltOf($this->page(DnssecAddKeyController::class));

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame([['success', 'Zone key has been added successfully.']], $this->messagesFor('dnssec'));
    }

    public function testASizeTheAlgorithmDoesNotTakeIsWordedPerAlgorithm(): void
    {
        $this->dnssec->expects($this->never())->method('createZoneKey');
        $this->submitKey('ecdsa256', '384');

        $this->page(DnssecAddKeyController::class)->run();

        $this->assertSame('dnssec_add_key.html', $this->renderedTemplate());
        $this->assertSame([['error', 'ECDSA P-256 algorithm must use 256 bits']], $this->messagesFor('dnssec_add_key'));
    }

    public function testAnAddWithoutAKeyTypeIsRefused(): void
    {
        $this->dnssec->expects($this->never())->method('createZoneKey');
        $this->post(['algorithm' => 'ecdsa256', 'bits' => '256', 'submit' => '1']);

        $halt = $this->haltOf($this->page(DnssecAddKeyController::class));

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame('Invalid or unexpected input given.', $halt->target);
    }

    public function testARefusedAddIsReportedOnTheForm(): void
    {
        $this->dnssec->method('createZoneKey')->willReturn(null);
        $this->audit->expects($this->never())->method('logDnssecAddKey');
        $this->submitKey('ecdsa256', '256');

        $this->page(DnssecAddKeyController::class)->run();

        $this->assertSame([['error', 'Failed to add new DNSSEC key.']], $this->messagesFor('dnssec_add_key'));
    }

    // ---------------------------------------------------------------- DNSSEC off

    /** @return array<string, array{0: class-string<BaseController>}> */
    public static function changePageProvider(): array
    {
        return [
            'add' => [DnssecAddKeyController::class],
            'toggle' => [DnssecToggleKeyController::class],
            'delete' => [DnssecDeleteKeyController::class],
        ];
    }

    /**
     * @param class-string<BaseController> $class
     */
    #[DataProvider('changePageProvider')]
    public function testAServerWithoutDnssecRefusesKeyChanges(string $class): void
    {
        $this->enabled = false;
        $this->dnssec->expects($this->never())->method('createZoneKey');
        $this->dnssec->expects($this->never())->method('deactivateZoneKey');
        $this->dnssec->expects($this->never())->method('removeZoneKey');
        $this->submitKey('ecdsa256', '256');

        $halt = $this->haltOf($this->page($class));

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame(self::OUTAGE, $halt->target);
    }

    // ---------------------------------------------------------------- PowerDNS out of reach

    /** @return array<string, array{0: class-string<BaseController>, 1: bool}> */
    public static function everyKeyPageProvider(): array
    {
        return [
            'add' => [DnssecAddKeyController::class, true],
            'toggle' => [DnssecToggleKeyController::class, true],
            'delete' => [DnssecDeleteKeyController::class, true],
            'delete confirmation' => [DnssecDeleteKeyController::class, false],
            'edit' => [DnssecEditKeyController::class, false],
        ];
    }

    /**
     * @param class-string<BaseController> $class
     */
    #[DataProvider('everyKeyPageProvider')]
    public function testAnUnreachablePowerDnsIsNotAMissingKey(string $class, bool $post): void
    {
        $this->keys = null;
        $this->dnssec->expects($this->never())->method('createZoneKey');
        $this->dnssec->expects($this->never())->method('activateZoneKey');
        $this->dnssec->expects($this->never())->method('deactivateZoneKey');
        $this->dnssec->expects($this->never())->method('removeZoneKey');
        $this->audit->expects($this->never())->method($this->anything());
        if ($post) {
            $this->submitKey('ecdsa256', '256');
        }

        $halt = $this->haltOf($this->page($class));

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame(self::OUTAGE, $halt->target);
    }
}
