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

use PoweradminInstall\InstallationSteps;
use Symfony\Component\Validator\Constraints as Assert;

class ConfiguringDatabaseValidator extends BaseValidator
{
    use DatabaseValidationTrait;
    use UserPasswordValidationTrait;
    use PdnsDatabaseValidationTrait;

    public function validate(): array
    {
        $constraints = new Assert\Collection(array_merge(
            $this->getBaseConstraints(),
            $this->getDatabaseConstraints(),
            $this->getPasswordValidationConstraints(),
            [
                'step' => [
                    new Assert\NotBlank(),
                    new Assert\EqualTo([
                        'value' => InstallationSteps::STEP_SETUP_ACCOUNT_AND_NAMESERVERS,
                        'message' => 'The step must be equal to ' . InstallationSteps::STEP_SETUP_ACCOUNT_AND_NAMESERVERS
                    ])
                ],
                // PowerDNS database field (optional)
                'pdns_db_name' => new Assert\Optional(),
                // PowerDNS API fields (optional)
                'pdns_api_backend' => new Assert\Optional(),
                'pdns_api_url' => new Assert\Optional(),
                'pdns_api_key' => new Assert\Optional(),
                'pdns_api_server_name' => new Assert\Optional(),
            ]
        ));

        $input = $this->input->request->all();
        $violations = $this->validator->validate($input, $constraints);

        $errors = ValidationErrorHelper::formatErrors($violations);

        // Add PowerDNS database validation (only for SQL backend)
        if (($input['pdns_api_backend'] ?? '') !== 'api') {
            $pdnsErrors = $this->validatePdnsDatabase($input);
            $errors = array_merge($errors, $pdnsErrors);
        }

        // Validate PowerDNS API settings when API backend is selected
        if (($input['pdns_api_backend'] ?? '') === 'api') {
            if (empty($input['pdns_api_url'] ?? '')) {
                $errors['pdns_api_url'] = _('PowerDNS API URL is required when using API backend.');
            }
            if (empty($input['pdns_api_key'] ?? '')) {
                $errors['pdns_api_key'] = _('PowerDNS API key is required when using API backend.');
            }
        }

        return $errors;
    }
}
