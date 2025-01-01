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

namespace Poweradmin\Infrastructure\Service;

use Poweradmin\AppConfiguration;
use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Domain\Service\DnssecProvider;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

class PdnsUtilProvider implements DnssecProvider
{
    private LegacyLogger $logger;
    private AppConfiguration $config;
    private PDOLayer $db;

    public function __construct($db)
    {
        $this->db = $db;
        $this->logger = new LegacyLogger($db);
        $this->config = new AppConfiguration();
    }

    private function dnssec_is_pdnssec_callable(): bool
    {
        $pdnssec_command = $this->config->get('pdnssec_command');

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

    private function dnssec_call_pdnssec($command, $domain, $args = array()): array
    {
        $pdnssec_command = $this->config->get('pdnssec_command');
        $pdnssec_debug = $this->config->get('pdnssec_debug');

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
        $pdnssec_command = $this->config->get('pdnssec_command');
        $pdnssec_debug = $this->config->get('pdnssec_debug');

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

        $this->logger->log_info(sprintf('client_ip:%s user:%s operation:dnssec_secure_zone zone:%s',
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

        $this->logger->log_info(sprintf('client_ip:%s user:%s operation:dnssec_unsecure_zone zone:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $zoneName));

        return true;
    }

    public function isZoneSecured(string $zoneName, $config): bool
    {
        $pdns_db_name = $config->get('pdns_db_name');
        $cryptokeys_table = $pdns_db_name ? $pdns_db_name . '.cryptokeys' : 'cryptokeys';
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
        $domainmetadata_table = $pdns_db_name ? $pdns_db_name . '.domainmetadata' : 'domainmetadata';

        $query = $this->db->prepare("SELECT
                  COUNT($cryptokeys_table.id) AS active_keys,
                  COUNT($domainmetadata_table.id) > 0 AS presigned
                  FROM $domains_table
                  LEFT JOIN $cryptokeys_table ON $domains_table.id = $cryptokeys_table.domain_id
                  LEFT JOIN $domainmetadata_table ON $domains_table.id = $domainmetadata_table.domain_id AND $domainmetadata_table.kind = 'PRESIGNED'
                  WHERE $domains_table.name = ?
                  GROUP BY $domains_table.id
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
            if (str_starts_with($line, 'DS')) {
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

        $this->logger->log_info(sprintf('client_ip:%s user:%s operation:dnssec_activate_zone_key zone:%s key_id:%s',
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

        $this->logger->log_info(sprintf('client_ip:%s user:%s operation:dnssec_deactivate_zone_key zone:%s key_id:%s',
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
            if (str_starts_with($line, 'ID')) {
                $items[0] = explode(' ', (explode('ID = ', $line)[1]))[0];
                $items[1] = substr(explode(' ', (explode('ID = ', $line)[1]))[1], 1, -2);
                $items[2] = substr(explode(' ', (explode('flags = ', $line)[1]))[0], 0, -1);
                $items[3] = substr(explode(' ', (explode('tag = ', $line)[1]))[0], 0, -1);
                $items[4] = substr(explode(' ', (explode('algo = ', $line)[1]))[0], 0, -1);
                $items[5] = preg_replace('/[^0-9]/', '', explode(' ', (explode('bits = ', $line)[1]))[0]);
                if (str_contains($line, 'Active')) {
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

        $this->logger->log_info(sprintf('client_ip:%s user:%s operation:dnssec_add_zone_key zone:%s type:%s bits:%s algorithm:%s',
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

        $this->logger->log_info(sprintf('client_ip:%s user:%s operation:dnssec_remove_zone_key zone:%s key_id:%s',
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

    public function isDnssecEnabled(): bool
    {
        return true; // There is no way to check if DNSSEC is enabled using pdnsutil.
    }
}