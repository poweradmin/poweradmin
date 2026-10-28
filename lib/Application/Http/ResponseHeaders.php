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

namespace Poweradmin\Application\Http;

use Poweradmin\Domain\Config\ConfigurationInterface;
use Psr\Log\LoggerInterface;

/**
 * Framing, content-type sniffing and referrer headers sent on every response;
 * Kernel::boot() calls send() once per process.
 */
final class ResponseHeaders
{
    private static bool $sent = false;

    private const ORIGIN_PATTERN = '#^https?://(\*\.)?[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:[0-9]{1,5})?$#';

    /**
     * @param list<mixed> $origins configured values; anything but a valid origin is dropped
     * @return array{headers: array<string, string>, rejected: list<string>}
     */
    public static function build(array $origins): array
    {
        $allowed = [];
        $rejected = [];
        foreach ($origins as $origin) {
            $candidate = is_string($origin) ? strtolower(trim($origin)) : '';
            if (preg_match(self::ORIGIN_PATTERN, $candidate) === 1) {
                $allowed[$candidate] = true;
            } else {
                $rejected[] = is_string($origin) ? $origin : get_debug_type($origin);
            }
        }

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
        ];
        // No plugin content, and an injected <base> cannot redirect relative script and form URLs
        $hardening = "; object-src 'none'; base-uri 'self'";
        if ($allowed === []) {
            $headers['X-Frame-Options'] = 'DENY';
            $headers['Content-Security-Policy'] = "frame-ancestors 'none'" . $hardening;
        } else {
            // X-Frame-Options cannot name several origins, so only the CSP is sent
            $headers['Content-Security-Policy'] = "frame-ancestors 'self' " . implode(' ', array_keys($allowed)) . $hardening;
        }

        return ['headers' => $headers, 'rejected' => $rejected];
    }

    public static function send(ConfigurationInterface $config, LoggerInterface $logger): void
    {
        $configured = $config->get('interface', 'frame_ancestors', []);
        if (!is_array($configured)) {
            $logger->warning('interface.frame_ancestors must be a list of origins; framing stays denied');
            $configured = [];
        }

        $built = self::build(array_values($configured));
        foreach ($built['rejected'] as $origin) {
            $logger->warning('Ignoring invalid interface.frame_ancestors entry', ['origin' => $origin]);
        }

        self::emit($built['headers']);
        self::$sent = true;
    }

    /** The default deny set, for a failure before send() ran; afterwards it must not override the configured set. */
    public static function sendDefaults(): void
    {
        if (self::$sent) {
            return;
        }
        self::emit(self::build([])['headers']);
    }

    public static function resetForTests(): void
    {
        self::$sent = false;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function emit(array $headers): void
    {
        if (headers_sent()) {
            return;
        }
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        // Removes an X-Frame-Options set earlier in PHP; a web-server header is out of reach
        if (!isset($headers['X-Frame-Options'])) {
            header_remove('X-Frame-Options');
        }
    }
}
