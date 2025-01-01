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

use Poweradmin\Application\Service\CsrfTokenService;
use PoweradminInstall\InstallationSteps;
use PoweradminInstall\LocaleHandler;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class SetupAccountAndNameServersValidator extends AbstractStepValidator
{
    private const DNS_HOSTNAME_REGEX = '/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.[A-Za-z0-9-]{1,63})*\.?$/';

    public function validate(): array
    {
        $constraints = new Assert\Collection([
            'install_token' => [
                new Assert\NotBlank(),
                new Assert\Length(['min' => CsrfTokenService::TOKEN_LENGTH, 'max' => CsrfTokenService::TOKEN_LENGTH]),
            ],
            'submit' => [
                new Assert\NotBlank(),
            ],
            'step' => [
                new Assert\NotBlank(),
                new Assert\EqualTo([
                    'value' => InstallationSteps::STEP_CREATE_LIMITED_RIGHTS_USER,
                    'message' => 'The step must be equal to ' . InstallationSteps::STEP_CREATE_LIMITED_RIGHTS_USER
                ])
            ],
            'language' => [
                new Assert\NotBlank(),
                new Assert\Choice(['choices' => LocaleHandler::getAvailableLanguages()]),
            ],
            'pa_db_user' => [
                new Assert\NotBlank(),
            ],
            'pa_db_pass' => [
                new Assert\NotBlank(),
            ],
            'dns_hostmaster' => [
                new Assert\NotBlank(),
                new Length([
                    'max' => 255,
                    'maxMessage' => 'The hostmaster hostname cannot be longer than {{ limit }} characters'
                ]),
                new Regex([
                    'pattern' => self::DNS_HOSTNAME_REGEX,
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
                    'pattern' => self::DNS_HOSTNAME_REGEX,
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
                    'pattern' => self::DNS_HOSTNAME_REGEX,
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
                        'pattern' => self::DNS_HOSTNAME_REGEX,
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
                        'pattern' => self::DNS_HOSTNAME_REGEX,
                        'message' => 'The 4th nameserver must be a valid hostname'
                    ]),
                    new Callback([$this, 'validateNameserver'])
                ])
            ],
            'db_user' => [
                new Assert\NotBlank(),
            ],
            'db_pass' => [
                new Assert\NotBlank(),
            ],
            'db_host' => [
                new Assert\NotBlank(),
            ],
            'db_port' => [
                new Assert\NotBlank(),
            ],
            'db_name' => [
                new Assert\NotBlank(),
            ],
            'db_type' => [
                new Assert\NotBlank(),
            ],
            'db_charset' => [
                new Assert\Optional(),
            ],
            'db_collation' => [
                new Assert\Optional(),
            ],
            'pa_pass' => [
                new Assert\NotBlank(),
            ],
        ]);

        $input = $this->request->request->all();
        $violations = $this->validator->validate($input, $constraints);

        return ValidationErrorHelper::formatErrors($violations);
    }

    public function validateHostname($value, ExecutionContextInterface $context): void
    {
        $labels = explode('.', $value);

        if (str_ends_with($value, '.')) {
            array_pop($labels);
        }

        foreach ($labels as $label) {
            if (strlen($label) > 63) {
                $context->buildViolation('Each part of the hostname cannot exceed 63 characters.')
                    ->addViolation();
                break;
            }

            if (!preg_match('/^(?!-)[A-Za-z0-9-]+(?<!-)$/', $label)) {
                $context->buildViolation('Each part of the hostname can only contain letters, numbers, and hyphens, and cannot start or end with a hyphen.')
                    ->addViolation();
                break;
            }
        }

        if (count($labels) < 2) {
            $context->buildViolation('The hostname must be fully qualified with at least two parts.')
                ->addViolation();
        }
    }

    public function validateNameserver($value, ExecutionContextInterface $context): void
    {
        if (empty($value)) {
            return;
        }

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
            $context->buildViolation('The nameserver must be a fully qualified domain name with at least two parts.')
                ->addViolation();
        }
    }
}
