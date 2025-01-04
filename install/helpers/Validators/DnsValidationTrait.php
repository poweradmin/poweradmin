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

namespace PoweradminInstall\Validators;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

trait DnsValidationTrait
{
    private static function getDnsHostnameRegex(): string
    {
        return '/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.[A-Za-z0-9-]{1,63})*\.?$/';
    }

    public function getDnsValidationConstraints(): array
    {
        return [
            'dns_hostmaster' => [
                new Assert\NotBlank(),
                new Length([
                    'max' => 255,
                    'maxMessage' => 'The hostmaster hostname cannot be longer than {{ limit }} characters'
                ]),
                new Regex([
                    'pattern' => self::getDnsHostnameRegex(),
                    'message' => 'The hostmaster must be a valid hostname'
                ]),
                new Callback([$this, 'validateHostname'])
            ],
            'dns_ns1' => [
                new Assert\NotBlank(),
                new Length([
                    'max' => 255,
                    'maxMessage' => 'The 1st nameserver hostname cannot be longer than {{ limit }} characters'
                ]),
                new Regex([
                    'pattern' => self::getDnsHostnameRegex(),
                    'message' => 'The 1st nameserver must be a valid hostname'
                ]),
                new Callback([$this, 'validateNameserver'])
            ],
            'dns_ns2' => [
                new Assert\NotBlank(),
                new Length([
                    'max' => 255,
                    'maxMessage' => 'The 2nd nameserver hostname cannot be longer than {{ limit }} characters'
                ]),
                new Regex([
                    'pattern' => self::getDnsHostnameRegex(),
                    'message' => 'The 2nd nameserver must be a valid hostname'
                ]),
                new Callback([$this, 'validateNameserver'])
            ],
            'dns_ns3' => [
                new Assert\Optional([
                    new Length([
                        'max' => 255,
                        'maxMessage' => 'The 3rd nameserver hostname cannot be longer than {{ limit }} characters'
                    ]),
                    new Regex([
                        'pattern' => self::getDnsHostnameRegex(),
                        'message' => 'The 3rd nameserver must be a valid hostname'
                    ]),
                    new Callback([$this, 'validateNameserver'])
                ])
            ],
            'dns_ns4' => [
                new Assert\Optional([
                    new Length([
                        'max' => 255,
                        'maxMessage' => 'The 4th nameserver hostname cannot be longer than {{ limit }} characters'
                    ]),
                    new Regex([
                        'pattern' => self::getDnsHostnameRegex(),
                        'message' => 'The 4th nameserver must be a valid hostname'
                    ]),
                    new Callback([$this, 'validateNameserver'])
                ])
            ],
        ];
    }


    public function validateHostname($value, ExecutionContextInterface $context): void
    {
        $this->validateFQDN($value, $context, 'hostname');
    }

    public function validateNameserver($value, ExecutionContextInterface $context): void
    {
        if (empty($value)) {
            return;
        }

        $this->validateFQDN($value, $context, 'nameserver');
    }

    public function validateFQDN(string $value, ExecutionContextInterface $context, string $type): void
    {
        $labels = explode('.', $value);

        if (str_ends_with($value, '.')) {
            array_pop($labels);
        }

        foreach ($labels as $label) {
            if (strlen($label) > 63) {
                $context->buildViolation('Each part of the nameserver hostname cannot exceed 63 characters.')
                    ->addViolation();
                break;
            }

            if (!preg_match('/^(?!-)[A-Za-z0-9-]+(?<!-)$/', $label)) {
                $context->buildViolation('Each part of the nameserver hostname can only contain letters, numbers, and hyphens, and cannot start or end with a hyphen.')
                    ->addViolation();
                break;
            }
        }

        if (count($labels) < 2) {
            $context->buildViolation("The $type must be a fully qualified domain name with at least two parts.")
                ->addViolation();
        }
    }
}
