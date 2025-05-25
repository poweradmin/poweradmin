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

namespace Poweradmin\Application\Service;

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class RecaptchaService
{
    private ConfigurationManager $configManager;
    private bool $enabled;
    private string $secretKey;
    private string $version;
    private float $v3Threshold;

    public function __construct(ConfigurationManager $configManager)
    {
        $this->configManager = $configManager;
        $this->enabled = $this->configManager->get('security', 'recaptcha.enabled', false);
        $this->secretKey = $this->configManager->get('security', 'recaptcha.secret_key', '');
        $this->version = $this->configManager->get('security', 'recaptcha.version', 'v3');
        $this->v3Threshold = (float) $this->configManager->get('security', 'recaptcha.v3_threshold', 0.5);
    }

    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->secretKey);
    }

    public function verify(string $response, string $remoteIp = '', string $expectedAction = 'login'): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        if (empty($response)) {
            return false;
        }

        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret' => $this->secretKey,
            'response' => $response,
        ];

        if (!empty($remoteIp)) {
            $data['remoteip'] = $remoteIp;
        }

        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data),
                'timeout' => 10,
            ],
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return false;
        }

        $responseData = json_decode($result, true);

        if (!isset($responseData['success']) || !$responseData['success']) {
            // Log error codes if present for debugging
            if (isset($responseData['error-codes'])) {
                // In production, these should be logged, not exposed to users
                // Common error codes: missing-input-secret, invalid-input-secret,
                // missing-input-response, invalid-input-response, bad-request, timeout-or-duplicate
            }
            return false;
        }

        // For v3, verify action and check score
        if ($this->version === 'v3') {
            // Verify the action matches what we expect
            if (isset($responseData['action']) && $responseData['action'] !== $expectedAction) {
                return false;
            }

            // Check the score
            if (isset($responseData['score'])) {
                return $responseData['score'] >= $this->v3Threshold;
            }
        }

        return true;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getSiteKey(): string
    {
        return $this->configManager->get('security', 'recaptcha.site_key', '');
    }
}
