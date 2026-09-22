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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Service\User\CreateUserCommand;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Turns a user create or update request, keyed by the API field names, into
 * the typed command UserManagementService takes. A value of the wrong shape
 * is refused here with the same wording and refusal the service used to give.
 */
class UserCommandFactory
{
    /**
     * @param array<string, mixed> $input The request fields under their API names
     * @return CreateUserCommand|array{success: false, message: string, refusal: Refusal, code: string}
     */
    public static function create(array $input): CreateUserCommand|array
    {
        $useLdap = self::useLdap($input);
        if (is_array($useLdap)) {
            return $useLdap;
        }

        $permTemplId = self::permissionTemplateId($input);
        if (is_array($permTemplId)) {
            return $permTemplId;
        }
        if (self::passwordMalformed($input)) {
            return self::passwordInvalid();
        }

        $active = array_key_exists('active', $input) && $input['active'] !== null ? self::flag($input['active']) : true;

        return new CreateUserCommand(
            self::text($input['username'] ?? null),
            self::password($input),
            self::text($input['fullname'] ?? null),
            self::text($input['email'] ?? null),
            self::text($input['description'] ?? null),
            $active,
            $permTemplId,
            $useLdap ?? false,
        );
    }

    /**
     * @param array<string, mixed> $input The request fields under their API names; absent ones stay unchanged
     * @return UpdateUserCommand|array{success: false, message: string, refusal: Refusal, code: string}
     */
    public static function update(array $input): UpdateUserCommand|array
    {
        $useLdap = self::useLdap($input);
        if (is_array($useLdap)) {
            return $useLdap;
        }
        if (self::passwordMalformed($input)) {
            return self::passwordInvalid();
        }

        $permTemplId = null;
        if (array_key_exists('perm_templ', $input)) {
            $permTemplId = self::permissionTemplateId($input);
            if (is_array($permTemplId)) {
                return $permTemplId;
            }
            if ($permTemplId === null) {
                return self::templateNotFound();
            }
        }

        return new UpdateUserCommand(
            array_key_exists('username', $input) ? self::text($input['username']) : null,
            self::password($input),
            array_key_exists('fullname', $input) ? self::text($input['fullname']) : null,
            array_key_exists('email', $input) ? self::text($input['email']) : null,
            array_key_exists('description', $input) ? self::text($input['description']) : null,
            array_key_exists('active', $input) ? self::flag($input['active']) : null,
            $permTemplId,
            $useLdap,
        );
    }

    /** Whether the request sets a password; "0" is a password, an empty string is not. */
    public static function passwordGiven(array $input): bool
    {
        return isset($input['password']) && is_scalar($input['password']) && (string)$input['password'] !== '';
    }

    /** An array or object password is neither a password nor its absence. */
    private static function passwordMalformed(array $input): bool
    {
        return array_key_exists('password', $input) && $input['password'] !== null && !is_scalar($input['password']);
    }

    /**
     * @return array{success: false, message: string, refusal: Refusal, code: string}
     */
    private static function passwordInvalid(): array
    {
        return [
            'success' => false,
            'message' => 'Invalid field types in request body',
            'refusal' => Refusal::INVALID_INPUT,
            'code' => UserManagementService::ERR_PASSWORD_POLICY,
        ];
    }

    private static function password(array $input): ?string
    {
        return self::passwordGiven($input) ? (string)$input['password'] : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /** The repository stored these as (int), so "true" counted as off and 2 as on. */
    private static function flag(mixed $value): bool
    {
        return is_scalar($value) && (int)$value !== 0;
    }

    /**
     * @return bool|null|array{success: false, message: string, refusal: Refusal, code: string}
     */
    private static function useLdap(array $input): bool|null|array
    {
        if (!array_key_exists('use_ldap', $input)) {
            return null;
        }

        $useLdap = filter_var($input['use_ldap'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($useLdap === null) {
            return [
                'success' => false,
                'message' => 'use_ldap must be a boolean',
                'refusal' => Refusal::INVALID_INPUT,
                'code' => UserManagementService::ERR_INVALID_LDAP,
            ];
        }

        return $useLdap;
    }

    /**
     * A positive int or all-digit string is the template id; an absent or null
     * value gives null; anything else (0, "admin", "2foo") is refused.
     *
     * @return int|null|array{success: false, message: string, refusal: Refusal, code: string}
     */
    private static function permissionTemplateId(array $input): int|null|array
    {
        $value = $input['perm_templ'] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value) && (int)$value > 0) {
            return (int)$value;
        }

        return self::templateNotFound();
    }

    /**
     * @return array{success: false, message: string, refusal: Refusal, code: string}
     */
    private static function templateNotFound(): array
    {
        return [
            'success' => false,
            'message' => 'Permission template not found',
            'refusal' => Refusal::INVALID_INPUT,
            'code' => UserManagementService::ERR_TEMPLATE_NOT_FOUND,
        ];
    }
}
