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

namespace Poweradmin\Application\Console\Command;

use DateTime;
use Exception;
use InvalidArgumentException;
use Poweradmin\Application\Console\Arguments;
use Poweradmin\Application\Console\CommandInterface;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Service\Auth\ApiKeyService;
use Throwable;

/**
 * api-keys:create - issues an API key owned by the acting user (--user=<id>) and
 * prints the secret once. ApiKeyService enforces the same rules as the web form:
 * API enabled, api_manage_keys or ueberuser, and api.max_keys_per_user.
 */
final class ApiKeyCreateCommand implements CommandInterface
{
    public const NAME = 'api-keys:create';

    private ApiKeyService $apiKeyService;
    private AuditService $auditService;

    public function __construct(ApiKeyService $apiKeyService, AuditService $auditService)
    {
        $this->apiKeyService = $apiKeyService;
        $this->auditService = $auditService;
    }

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Issue an API key owned by the user named by --user and print the secret once';
    }

    public static function options(): array
    {
        return ['user', 'name', 'expires', 'readonly'];
    }

    public function run(Arguments $arguments, ActorInterface $actor, $stdout, $stderr): int
    {
        if ($arguments->positionals() !== []) {
            throw new InvalidArgumentException(self::NAME . ' takes no arguments');
        }
        if ($actor->userId() === null) {
            throw new InvalidArgumentException(self::NAME . ' needs --user=<id>, the user who will own the key');
        }

        $name = trim($arguments->value('name') ?? '');
        if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new InvalidArgumentException('Option --name expects a label for the key of up to 255 characters, without control characters');
        }

        $expiresAt = null;
        if ($arguments->has('expires')) {
            $expiresAt = $this->parseDate($arguments->value('expires'));
        }

        if ($arguments->has('readonly') && $arguments->value('readonly') !== null) {
            throw new InvalidArgumentException('Option --readonly takes no value');
        }

        $created = $this->apiKeyService->createApiKey($name, $expiresAt, $arguments->has('readonly'));
        if (!$created->success || $created->key === null) {
            fwrite($stderr, 'Error: ' . ($created->message ?? 'The API key could not be created') . "\n");
            return 1;
        }

        $key = $created->key;
        // Only the secret goes to stdout so a script can capture it directly; printed before the
        // audit write, which could fail after the key is stored and lose its only copy
        fwrite($stdout, $key->getSecretKey() . "\n");
        try {
            $this->auditService->logApiKeyCreate((int) $key->getId(), $key->getName());
        } catch (Throwable $e) {
            // The key exists either way; a failure exit would invite a retry and a second key
            fwrite($stderr, 'Warning: the key was created but the audit log could not be written: ' . $e->getMessage() . "\n");
        }
        fwrite($stderr, sprintf(
            "Created API key %d \"%s\" for user %s; the secret is shown only once.\n",
            (int) $key->getId(),
            $key->getName(),
            (string) $actor->username()
        ));

        return 0;
    }

    private function parseDate(?string $value): DateTime
    {
        try {
            if ($value !== null && $value !== '') {
                $date = new DateTime($value);
                // DateTime rolls an impossible date like 2027-02-30 over with only a warning, and
                // reads a five-digit year as a past date; a key must expire in the future
                $errors = DateTime::getLastErrors() ?: ['warning_count' => 0, 'error_count' => 0];
                if ($errors['warning_count'] === 0 && $errors['error_count'] === 0 && $date > new DateTime()) {
                    return $date;
                }
            }
        } catch (Exception) {
            // Falls through to the usage error below
        }

        throw new InvalidArgumentException('Option --expires expects a future date such as 2027-01-31');
    }
}
