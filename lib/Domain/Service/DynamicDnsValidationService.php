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

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Poweradmin\Domain\ValueObject\HostnameValue;
use Poweradmin\Domain\ValueObject\IpAddressList;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class DynamicDnsValidationService
{
    private HostnameValidator $hostnameValidator;
    private IPAddressValidator $ipValidator;
    private ConfigurationManager $config;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ipValidator = new IPAddressValidator();
    }

    public function validateRequest(DynamicDnsRequest $request): ValidationResult
    {
        $errors = [];

        if (!$request->hasUserAgent()) {
            $errors[] = 'User agent is required';
        }

        if (!$request->hasUsername()) {
            $errors[] = 'Username is required';
        }

        if (!$request->hasHostname()) {
            $errors[] = 'Hostname is required';
        } else {
            $hostnameValidation = $this->validateHostname($request->getHostname());
            if (!$hostnameValidation->isValid()) {
                $errors = array_merge($errors, $hostnameValidation->getErrors());
            }
        }

        if (!$request->hasIpAddresses()) {
            $errors[] = 'At least one IP address is required';
        } else {
            $ipValidation = $this->validateIpAddresses($request->getIpv4(), $request->getIpv6());
            if (!$ipValidation->isValid()) {
                $errors = array_merge($errors, $ipValidation->getErrors());
            }
        }

        return empty($errors)
            ? ValidationResult::success(null)
            : ValidationResult::failure($errors);
    }

    public function validateHostname(string $hostname): ValidationResult
    {
        $result = $this->hostnameValidator->validate($hostname);
        if (!$result->isValid()) {
            return ValidationResult::failure(['Invalid hostname: ' . implode(', ', $result->getErrors())]);
        }
        return ValidationResult::success(null);
    }

    public function validateIpAddresses(string $ipv4String, string $ipv6String): ValidationResult
    {
        try {
            $ipList = IpAddressList::fromCommaSeparatedStrings($ipv4String, $ipv6String);

            if ($ipList->isEmpty()) {
                return ValidationResult::failure(['At least one valid IP address is required']);
            }

            return ValidationResult::success(null);
        } catch (\Exception $e) {
            return ValidationResult::failure(['Invalid IP addresses: ' . $e->getMessage()]);
        }
    }

    public function createValidatedHostname(string $hostname): HostnameValue
    {
        $validation = $this->validateHostname($hostname);
        if (!$validation->isValid()) {
            throw new \InvalidArgumentException('Invalid hostname: ' . implode(', ', $validation->getErrors()));
        }

        return new HostnameValue($hostname, $this->config);
    }

    public function createValidatedIpList(string $ipv4String, string $ipv6String): IpAddressList
    {
        $validation = $this->validateIpAddresses($ipv4String, $ipv6String);
        if (!$validation->isValid()) {
            throw new \InvalidArgumentException('Invalid IP addresses: ' . implode(', ', $validation->getErrors()));
        }

        return IpAddressList::fromCommaSeparatedStrings($ipv4String, $ipv6String);
    }
}
