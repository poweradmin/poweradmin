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

namespace Poweradmin\Application\Service\Auth;

use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\ValueObject\RemoteUserInfo;
use Poweradmin\Infrastructure\Utility\TrustedProxyList;
use Psr\Log\LoggerInterface;

/**
 * Reads the user the web server or an authenticating proxy signed in, from the
 * remote_user settings. A server variable is set by the web server itself; a
 * header is believed only from an address in remote_user.trusted_proxies.
 */
final class RemoteUserIdentitySource
{
    private const MAX_USERNAME_LENGTH = 64;
    private const MAX_FULLNAME_LENGTH = 255;

    /**
     * @param array<string, mixed> $server The request's server variables ($_SERVER)
     */
    public function __construct(
        private readonly ConfigurationInterface $config,
        private readonly array $server,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool)$this->config->get('remote_user', 'enabled', false);
    }

    /**
     * The signed-in user, or null when the feature is off, nobody is signed in,
     * or the value cannot be trusted or used as a username.
     */
    public function identity(): ?RemoteUserInfo
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $header = trim((string)$this->config->get('remote_user', 'header', ''));
        if ($header !== '') {
            if (!$this->requestComesFromTrustedProxy()) {
                return null;
            }
            $read = fn(string $name): string => $this->serverValue(self::headerKey($name));
            $usernameSource = $header;
        } else {
            $usernameSource = trim((string)$this->config->get('remote_user', 'server_variable', 'REMOTE_USER'));
            $read = fn(string $name): string => $this->serverVariable($name);
        }

        $username = $this->normalizeUsername($read($usernameSource));
        if ($username === null) {
            return null;
        }

        return new RemoteUserInfo(
            $username,
            $this->email($this->readAttribute('email_attribute', $read)),
            $this->fullname($this->readAttribute('name_attribute', $read)),
            $this->groups($this->readAttribute('groups_attribute', $read))
        );
    }

    private function requestComesFromTrustedProxy(): bool
    {
        $peer = (string)($this->server['REMOTE_ADDR'] ?? '');
        $proxies = $this->config->get('remote_user', 'trusted_proxies', []);
        if ($peer !== '' && (new TrustedProxyList(is_array($proxies) ? $proxies : []))->matches($peer)) {
            return true;
        }

        $this->logger->debug('Ignoring the remote_user header from {peer}, which is not in remote_user.trusted_proxies', ['peer' => $peer]);
        return false;
    }

    /**
     * A variable the web server sets. HTTP_* names carry client-sent headers and PHP
     * fills PHP_AUTH_* from the client's Authorization header, so anyone could forge
     * them; they are refused here, as are Apache's REDIRECT_ copies of them.
     */
    private function serverVariable(string $name): string
    {
        if ($name === '') {
            return '';
        }
        if (preg_match('/^(REDIRECT_)*(HTTP_|PHP_AUTH_)/i', $name) === 1) {
            $this->logger->error('Ignoring remote_user variable {name}: the client can set it, configure remote_user.header for a proxy header instead', ['name' => $name]);
            return '';
        }

        return $this->serverValue($name);
    }

    private static function headerKey(string $header): string
    {
        return 'HTTP_' . strtoupper(str_replace('-', '_', trim($header)));
    }

    private function serverValue(string $key): string
    {
        $value = $this->server[$key] ?? '';

        // Only spaces are trimmed; a value with control characters is refused, not repaired
        return is_string($value) ? trim($value, ' ') : '';
    }

    /**
     * @param callable(string): string $read
     */
    private function readAttribute(string $setting, callable $read): string
    {
        $name = trim((string)$this->config->get('remote_user', $setting, ''));

        return $name === '' ? '' : $read($name);
    }

    private function normalizeUsername(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if ($this->config->get('remote_user', 'strip_realm', false)) {
            // DOMAIN\user and user@REALM both name "user"
            $backslash = strrpos($value, '\\');
            if ($backslash !== false) {
                $value = substr($value, $backslash + 1);
            }
            $at = strrpos($value, '@');
            if ($at !== false) {
                $value = substr($value, 0, $at);
            }
        }

        if (
            $value === ''
            || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > self::MAX_USERNAME_LENGTH
            || preg_match('/[\s\p{Cc}]/u', $value) === 1
        ) {
            $printable = (string)preg_replace('/\p{Cc}/u', '?', mb_scrub($value, 'UTF-8'));
            $this->logger->warning('Ignoring unusable remote_user name {name}', ['name' => mb_substr($printable, 0, self::MAX_USERNAME_LENGTH)]);
            return null;
        }

        return $value;
    }

    private function email(string $value): string
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : '';
    }

    private function fullname(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        return mb_substr(trim((string)preg_replace('/\p{Cc}/u', '', $value)), 0, self::MAX_FULLNAME_LENGTH);
    }

    /**
     * @return list<string>
     */
    private function groups(string $value): array
    {
        if ($value === '' || !mb_check_encoding($value, 'UTF-8')) {
            return [];
        }

        $separator = (string)$this->config->get('remote_user', 'groups_separator', ',');
        $parts = $separator === '' ? [$value] : explode($separator, $value);

        // Groups only feed the mappings, but a control character must not reach a log line
        $groups = array_map(static fn(string $group): string => trim((string)preg_replace('/\p{Cc}/u', '', $group)), $parts);

        return array_values(array_unique(array_filter($groups, static fn(string $group): bool => $group !== '')));
    }
}
