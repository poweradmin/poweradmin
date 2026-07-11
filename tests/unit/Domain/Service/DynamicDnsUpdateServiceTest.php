<?php

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\DynamicDnsRepositoryInterface;
use Poweradmin\Domain\Service\DynamicDnsAuthenticationService;
use Poweradmin\Domain\Service\DynamicDnsUpdateService;
use Poweradmin\Domain\Service\DynamicDnsValidationService;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Poweradmin\Domain\ValueObject\HostnameValue;
use Poweradmin\Domain\ValueObject\IpAddressList;
use Poweradmin\Domain\Service\Validation\ValidationResult;

class DynamicDnsUpdateServiceTest extends TestCase
{
    private DynamicDnsUpdateService $service;
    private MockObject $validationService;
    private MockObject $authService;
    private MockObject $repository;

    protected function setUp(): void
    {
        $this->validationService = $this->createMock(DynamicDnsValidationService::class);
        $this->authService = $this->createMock(DynamicDnsAuthenticationService::class);
        $this->repository = $this->createMock(DynamicDnsRepositoryInterface::class);

        $this->service = new DynamicDnsUpdateService(
            $this->validationService,
            $this->authService,
            $this->repository
        );
    }

    public function testProcessUpdateReturnsGoodWhenUpdateSuccessful(): void
    {
        $request = new DynamicDnsRequest(
            'user',
            'pass',
            'test.example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $validationResult = ValidationResult::success(null);
        $user = new User(1, 'hashedpass', false);
        $hostname = new HostnameValue('test.example.com');
        $ipList = new IpAddressList(['192.168.1.1'], []);

        $this->validationService->expects($this->once())
            ->method('validateRequest')
            ->with($request)
            ->willReturn($validationResult);

        $this->authService->expects($this->once())
            ->method('authenticateUser')
            ->with($request)
            ->willReturn($user);

        $this->validationService->expects($this->once())
            ->method('createValidatedHostname')
            ->with('test.example.com')
            ->willReturn($hostname);

        $this->validationService->expects($this->once())
            ->method('createValidatedIpList')
            ->with('192.168.1.1', '')
            ->willReturn($ipList);

        $this->authService->expects($this->once())
            ->method('getUserZones')
            ->with($user)
            ->willReturn([1 => 'example.com']);

        $this->repository->expects($this->once())
            ->method('getDnsRecords')
            ->with(1, $hostname, 'A')
            ->willReturn([]);

        $this->repository->expects($this->once())
            ->method('insertDnsRecord')
            ->with(1, $hostname, 'A', '192.168.1.1');

        $this->repository->expects($this->once())
            ->method('updateSOASerial')
            ->with(1);

        $result = $this->service->processUpdate($request);

        $this->assertEquals('good 192.168.1.1', $result);
    }

    public function testProcessUpdateRejectsReadOnlyZone(): void
    {
        $request = new DynamicDnsRequest(
            'user',
            'pass',
            'test.example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $validationResult = ValidationResult::success(null);
        $user = new User(1, 'hashedpass', false);
        $hostname = new HostnameValue('test.example.com');
        $ipList = new IpAddressList(['192.168.1.1'], []);

        $this->validationService->method('validateRequest')->willReturn($validationResult);
        $this->authService->method('authenticateUser')->willReturn($user);
        $this->validationService->method('createValidatedHostname')->willReturn($hostname);
        $this->validationService->method('createValidatedIpList')->willReturn($ipList);
        $this->authService->method('getUserZones')->willReturn([1 => 'example.com']);

        // Secondary/Consumer zones replicate from a primary - no records may be written
        $this->repository->method('getZoneType')->with(1)->willReturn('SLAVE');
        $this->repository->expects($this->never())->method('insertDnsRecord');
        $this->repository->expects($this->never())->method('deleteDnsRecord');
        $this->repository->expects($this->never())->method('updateSOASerial');

        $result = $this->service->processUpdate($request);

        $this->assertEquals('!yours', $result);
    }

    public function testProcessUpdateReturnsNochgWhenNoUpdateNeeded(): void
    {
        $request = new DynamicDnsRequest(
            'user',
            'pass',
            'test.example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $validationResult = ValidationResult::success(null);
        $user = new User(1, 'hashedpass', false);
        $hostname = new HostnameValue('test.example.com');
        $ipList = new IpAddressList(['192.168.1.1'], []);

        $this->validationService->expects($this->once())
            ->method('validateRequest')
            ->with($request)
            ->willReturn($validationResult);

        $this->authService->expects($this->once())
            ->method('authenticateUser')
            ->with($request)
            ->willReturn($user);

        $this->validationService->expects($this->once())
            ->method('createValidatedHostname')
            ->with('test.example.com')
            ->willReturn($hostname);

        $this->validationService->expects($this->once())
            ->method('createValidatedIpList')
            ->with('192.168.1.1', '')
            ->willReturn($ipList);

        $this->authService->expects($this->once())
            ->method('getUserZones')
            ->with($user)
            ->willReturn([1 => 'example.com']);

        $this->repository->expects($this->once())
            ->method('getDnsRecords')
            ->with(1, $hostname, 'A')
            ->willReturn(['192.168.1.1' => 123]); // Existing record with same IP

        $this->repository->expects($this->never())
            ->method('insertDnsRecord');

        $this->repository->expects($this->never())
            ->method('deleteDnsRecord');

        $this->repository->expects($this->never())
            ->method('updateSOASerial');

        $result = $this->service->processUpdate($request);

        $this->assertEquals('nochg 192.168.1.1', $result); // dyndns2 returns 'nochg <ip>' when records already match
    }

    public function testProcessUpdateWithDualstackClearsOppositeRecords(): void
    {
        $request = new DynamicDnsRequest(
            'user',
            'pass',
            'test.example.com',
            '192.168.1.1',
            '', // No IPv6 provided
            true, // Dualstack update
            'TestAgent/1.0'
        );

        $validationResult = ValidationResult::success(null);
        $user = new User(1, 'hashedpass', false);
        $hostname = new HostnameValue('test.example.com');
        $ipList = new IpAddressList(['192.168.1.1'], []);

        $this->validationService->expects($this->once())
            ->method('validateRequest')
            ->with($request)
            ->willReturn($validationResult);

        $this->authService->expects($this->once())
            ->method('authenticateUser')
            ->with($request)
            ->willReturn($user);

        $this->validationService->expects($this->once())
            ->method('createValidatedHostname')
            ->with('test.example.com')
            ->willReturn($hostname);

        $this->validationService->expects($this->once())
            ->method('createValidatedIpList')
            ->with('192.168.1.1', '')
            ->willReturn($ipList);

        $this->authService->expects($this->once())
            ->method('getUserZones')
            ->with($user)
            ->willReturn([1 => 'example.com']);

        $this->repository->expects($this->exactly(2))
            ->method('getDnsRecords')
            ->willReturnCallback(function ($zoneId, $hostname, $recordType) {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    $this->assertEquals(1, $zoneId);
                    $this->assertEquals('A', $recordType);
                    return []; // No existing A records
                } else {
                    $this->assertEquals(1, $zoneId);
                    $this->assertEquals('AAAA', $recordType);
                    return ['2001:db8::1' => 456]; // Existing AAAA record to be deleted
                }
            });

        $this->repository->expects($this->once())
            ->method('insertDnsRecord')
            ->with(1, $hostname, 'A', '192.168.1.1');

        $this->repository->expects($this->once())
            ->method('deleteDnsRecord')
            ->with(456); // Delete existing AAAA record

        $this->repository->expects($this->once())
            ->method('updateSOASerial')
            ->with(1);

        $result = $this->service->processUpdate($request);

        $this->assertEquals('good 192.168.1.1', $result);
    }

    public function testProcessUpdateReturnsNotYoursWhenNoValidRecords(): void
    {
        $request = new DynamicDnsRequest(
            'user',
            'pass',
            'test.example.com',
            '',
            '',
            false,
            'TestAgent/1.0'
        );

        $validationResult = ValidationResult::success(null);
        $user = new User(1, 'hashedpass', false);
        $hostname = new HostnameValue('test.example.com');
        $ipList = new IpAddressList([], []);

        $this->validationService->expects($this->once())
            ->method('validateRequest')
            ->with($request)
            ->willReturn($validationResult);

        $this->authService->expects($this->once())
            ->method('authenticateUser')
            ->with($request)
            ->willReturn($user);

        $this->validationService->expects($this->once())
            ->method('createValidatedHostname')
            ->with('test.example.com')
            ->willReturn($hostname);

        $this->validationService->expects($this->once())
            ->method('createValidatedIpList')
            ->with('', '')
            ->willReturn($ipList);

        $this->authService->expects($this->once())
            ->method('getUserZones')
            ->with($user)
            ->willReturn([1 => 'example.com']);

        $this->repository->expects($this->never())
            ->method('getDnsRecords');

        $this->repository->expects($this->never())
            ->method('insertDnsRecord');

        $this->repository->expects($this->never())
            ->method('deleteDnsRecord');

        $this->repository->expects($this->never())
            ->method('updateSOASerial');

        $result = $this->service->processUpdate($request);

        $this->assertEquals('!yours', $result);
    }

    public function testProcessUpdateReturnsBadauthWhenUserNotAuthenticated(): void
    {
        $request = new DynamicDnsRequest(
            'user',
            'wrongpass',
            'test.example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $validationResult = ValidationResult::success(null);

        $this->validationService->expects($this->once())
            ->method('validateRequest')
            ->with($request)
            ->willReturn($validationResult);

        $this->authService->expects($this->once())
            ->method('authenticateUser')
            ->with($request)
            ->willReturn(null);

        $result = $this->service->processUpdate($request);

        $this->assertEquals('badauth', $result);
    }

    public function testProcessUpdateReturnsNohostWhenHostnameDoesntMatchAnyOwnedZone(): void
    {
        $request = new DynamicDnsRequest(
            'user',
            'pass',
            'host.other.example.org',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $validationResult = ValidationResult::success(null);
        $user = new User(1, 'hashedpass', false);
        $hostname = new HostnameValue('host.other.example.org');
        $ipList = new IpAddressList(['192.168.1.1'], []);

        $this->validationService->expects($this->once())
            ->method('validateRequest')->with($request)->willReturn($validationResult);
        $this->authService->expects($this->once())
            ->method('authenticateUser')->with($request)->willReturn($user);
        $this->validationService->expects($this->once())
            ->method('createValidatedHostname')->willReturn($hostname);
        $this->validationService->expects($this->once())
            ->method('createValidatedIpList')->willReturn($ipList);
        $this->authService->expects($this->once())
            ->method('getUserZones')->with($user)
            ->willReturn([1 => 'example.com']);

        $this->repository->expects($this->never())->method('insertDnsRecord');
        $this->repository->expects($this->never())->method('updateSOASerial');

        $this->assertEquals('nohost', $this->service->processUpdate($request));
    }

    public function testProcessUpdatePicksMostSpecificMatchingZone(): void
    {
        $request = new DynamicDnsRequest(
            'user',
            'pass',
            'host.sub.example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $validationResult = ValidationResult::success(null);
        $user = new User(1, 'hashedpass', false);
        $hostname = new HostnameValue('host.sub.example.com');
        $ipList = new IpAddressList(['192.168.1.1'], []);

        $this->validationService->expects($this->once())
            ->method('validateRequest')->with($request)->willReturn($validationResult);
        $this->authService->expects($this->once())
            ->method('authenticateUser')->with($request)->willReturn($user);
        $this->validationService->expects($this->once())
            ->method('createValidatedHostname')->willReturn($hostname);
        $this->validationService->expects($this->once())
            ->method('createValidatedIpList')->willReturn($ipList);
        $this->authService->expects($this->once())
            ->method('getUserZones')->with($user)
            ->willReturn([1 => 'example.com', 2 => 'sub.example.com', 3 => 'other.example.com']);

        $this->repository->expects($this->once())
            ->method('getDnsRecords')->with(2, $hostname, 'A')->willReturn([]);
        $this->repository->expects($this->once())
            ->method('insertDnsRecord')->with(2, $hostname, 'A', '192.168.1.1');
        $this->repository->expects($this->once())
            ->method('updateSOASerial')->with(2);

        $this->assertEquals('good 192.168.1.1', $this->service->processUpdate($request));
    }

    public function testProcessUpdateReturnsValidationErrorWhenRequestInvalid(): void
    {
        $request = new DynamicDnsRequest(
            '',
            'pass',
            'test.example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $validationResult = ValidationResult::failure(['Username is required']);

        $this->validationService->expects($this->once())
            ->method('validateRequest')
            ->with($request)
            ->willReturn($validationResult);

        $result = $this->service->processUpdate($request);

        $this->assertEquals('badauth', $result);
    }
}
