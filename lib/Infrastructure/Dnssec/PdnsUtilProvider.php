<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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

namespace Poweradmin\Infrastructure\Dnssec;

use Poweradmin\Domain\Dnssec\DnssecProvider;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Infrastructure\UI\ErrorPresenter;
use Poweradmin\LegacyLogger;

class PdnsUtilProvider implements DnssecProvider
{
    private static function dnssec_is_pdnssec_callable(): bool
    {
        global $pdnssec_command;

        if (!function_exists('exec')) {
            $error = new ErrorMessage(_('Failed to call function exec. Make sure that exec is not listed in disable_functions at php.ini'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        if (!file_exists($pdnssec_command) || !is_executable($pdnssec_command)) {
            $error = new ErrorMessage(_('Failed to call pdnssec utility.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        return true;
    }

    private static function dnssec_call_pdnssec($command, $domain, $args = array()): array
    {
        global $pdnssec_command, $pdnssec_debug;
        $output = '';
        $return_code = -1;

        if (!self::dnssec_is_pdnssec_callable()) {
            return array($output, $return_code);
        }

        if (!is_array($args)) {
            return array('ERROR: internal error, input not Array ()', $return_code);
        } else {
            foreach ($args as $k => $v) {
                $args [$k] = escapeshellarg($v);
            }
            $args = join(' ', $args);
        }

        $full_command = join(' ', array(
                escapeshellcmd($pdnssec_command),
                $command,
                escapeshellarg($domain) . ' ' . $args,
                $pdnssec_debug ? '2>&1' : ''
            )
        );

        exec($full_command, $output, $return_code);

        if ($pdnssec_debug) {
            echo "<div class=\"container\"><pre>";
            echo sprintf("Command: %s\n", $full_command);
            echo sprintf("Return code: %s\n", $return_code);
            echo sprintf("Output: %s", implode("\n", $output));
            echo "</pre></div>";
        }

        return array($output, $return_code);
    }

    public function rectifyZone(string $zoneName): bool
    {
        global $pdnssec_command, $pdnssec_debug;

        $output = array();

        if (isset($pdnssec_command)) {
            $full_command = join(' ', array(
                escapeshellcmd($pdnssec_command),
                'rectify-zone',
                escapeshellarg($zoneName),
                $pdnssec_debug ? '2>&1' : ''
            ));

            if (!self::dnssec_is_pdnssec_callable()) {
                return false;
            }

            exec($full_command, $output, $return_code);

            if ($pdnssec_debug) {
                echo "<div class=\"container\"><pre>";
                echo sprintf("Command: %s\n", $full_command);
                echo sprintf("Return code: %s\n", $return_code);
                echo sprintf("Output: %s", implode("\n", $output));
                echo "</pre></div>";
            }

            if ($return_code != 0) {
                return false;
            }

            return true;
        }

        return false;
    }

    public function secureZone(string $zoneName): bool
    {
        $call_result = self::dnssec_call_pdnssec('secure-zone', $zoneName);
        $return_code = $call_result[1];

        if ($return_code != 0) {
            $error = new ErrorMessage(_('Failed to secure zone.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        LegacyLogger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_secure_zone zone:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $zoneName));

        return true;
    }

    public function unsecureZone(string $zoneName): bool
    {
        $call_result = self::dnssec_call_pdnssec('disable-dnssec', $zoneName);
        $return_code = $call_result[1];

        if ($return_code != 0) {
            $error = new ErrorMessage(_('Failed to disable DNSSEC.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        LegacyLogger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_unsecure_zone zone:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $zoneName));

        return true;
    }

    public function isZoneSecured(string $zoneName): bool
    {
        global $db;
        $query = $db->prepare("SELECT
                  COUNT(cryptokeys.id) AS active_keys,
                  COUNT(domainmetadata.id) > 0 AS presigned
                  FROM domains
                  LEFT JOIN cryptokeys ON domains.id = cryptokeys.domain_id
                  LEFT JOIN domainmetadata ON domains.id = domainmetadata.domain_id AND domainmetadata.kind = 'PRESIGNED'
                  WHERE domains.name = ?
                  GROUP BY domains.id
        ");
        $query->execute(array($zoneName));
        $row = $query->fetch();
        return $row['active_keys'] > 0 || $row['presigned'];
    }

    public function getDsRecords(string $zoneName): array
    {
        $call_result = self::dnssec_call_pdnssec('show-zone', $zoneName);
        $output = $call_result[0];
        $return_code = $call_result[1];

        if ($return_code != 0) {
            $error = new ErrorMessage(_('Failed to get DNSSEC key details.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return [];
        }

        $ds_records = array();
        $id = 0;
        foreach ($output as $line) {
            if (substr($line, 0, 2) == 'DS') {
                $oldid = $id;
                $items = explode(' ', $line);

                $ds_line = join(" ", array_slice($items, 2));
                $id = $items[5];
                if ($oldid != $id and $oldid != 0) {
                    $ds_records[] = "<br/>" . $ds_line;
                } else {
                    $ds_records[] = $ds_line;
                }
            }
        }

        return $ds_records;
    }

    public function getDnsKeyRecords(string $zoneName): array
    {
        $call_result = self::dnssec_call_pdnssec('show-zone', $zoneName);
        $output = $call_result[0];
        $return_code = $call_result[1];

        if ($return_code != 0) {
            $error = new ErrorMessage(_('Failed to get DNSSEC key details.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return [];
        }

        $dns_keys = array();
        foreach ($output as $line) {
            if (in_array(substr($line, 0, 3), ['CSK', 'KSK', 'ZSK', 'ID '], true)) {
                $items = explode(' ', $line);
                $dns_key = join(" ", array_slice($items, 3));
                $dns_keys[] = $dns_key;
            }
        }
        return $dns_keys;
    }

    public function activateZoneKey(string $zoneName, int $keyId): bool
    {
        $call_result = self::dnssec_call_pdnssec('activate-zone-key', $zoneName, array($keyId));
        $return_code = $call_result[1];

        if ($return_code != 0) {
            $error = new ErrorMessage(_('Failed to active DNSSEC key.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        LegacyLogger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_activate_zone_key zone:%s key_id:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $zoneName, $keyId));

        return true;
    }

    public function deactivateZoneKey(string $zoneName, int $keyId): bool
    {
        $call_result = self::dnssec_call_pdnssec('deactivate-zone-key', $zoneName, array($keyId));
        $return_code = $call_result[1];

        if ($return_code != 0) {
            $error = new ErrorMessage(_('Failed to deactivate DNSSEC key.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        LegacyLogger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_deactivate_zone_key zone:%s key_id:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $zoneName, $keyId));

        return true;
    }

    public function getKeys(string $zoneName): array
    {
        $call_result = self::dnssec_call_pdnssec('show-zone', $zoneName);
        $output = $call_result[0];
        $return_code = $call_result[1];

        if ($return_code != 0) {
            $error = new ErrorMessage(_('Failed to get DNSSEC key details.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return [];
        }

        $keys = array();
        foreach ($output as $line) {
            if (substr($line, 0, 2) == 'ID') {
                $items[0] = explode(' ', (explode('ID = ', $line)[1]))[0];
                $items[1] = substr(explode(' ', (explode('ID = ', $line)[1]))[1], 1, -2);
                $items[2] = substr(explode(' ', (explode('flags = ', $line)[1]))[0], 0, -1);
                $items[3] = substr(explode(' ', (explode('tag = ', $line)[1]))[0], 0, -1);
                $items[4] = substr(explode(' ', (explode('algo = ', $line)[1]))[0], 0, -1);
                $items[5] = preg_replace('/[^0-9]/', '', explode(' ', (explode('bits = ', $line)[1]))[0]);
                if (strpos($line, 'Active') !== false) {
                    $items[6] = 1;
                } else {
                    $items[6] = 0;
                }
                $keys[] = array($items[0], $items[1], $items[3], $items[4], $items[5], $items[6]);
            }
        }

        return $keys;
    }

    public function addZoneKey(string $zoneName, string $keyType, int $keySize, string $algorithm): bool
    {
        $call_result = self::dnssec_call_pdnssec('add-zone-key', $zoneName, array($keyType, $keySize, "inactive", $algorithm));
        $return_code = $call_result[1];

        if ($return_code != 0) {
            $error = new ErrorMessage(_('Failed to add new DNSSEC key.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        LegacyLogger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_add_zone_key zone:%s type:%s bits:%s algorithm:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $zoneName, $keyType, $keySize, $algorithm));

        return true;
    }

    public function removeZoneKey(string $zoneName, int $keyId): bool
    {
        $call_result = self::dnssec_call_pdnssec('remove-zone-key', $zoneName, array($keyId));
        $return_code = $call_result[1];

        if ($return_code != 0) {
            $error = new ErrorMessage(_('Failed to remove DNSSEC key.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        LegacyLogger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_remove_zone_key zone:%s key_id:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $zoneName, $keyId));

        return true;
    }

    public function keyExists(string $zoneName, int $keyId): bool
    {
        $keys = $this->getKeys($zoneName);

        foreach ($keys as $key) {
            if ($key[0] == $keyId) {
                return true;
            }
        }

        return false;
    }

    public function getZoneKey(string $zoneName, int $keyId): array
    {
        $keys = $this->getKeys($zoneName);

        foreach ($keys as $key) {
            if ($key[0] == $keyId) {
                return $key;
            }
        }

        return array();
    }
}