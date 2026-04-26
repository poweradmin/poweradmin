#!/usr/bin/env php
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

/**
 * Email digest of record/zone changes.
 *
 * Reads the log_record_changes table for the given time window and emails
 * an HTML diff report. Designed to be invoked from cron.
 *
 * Usage:
 *   php scripts/send_record_changes_email.php --to=ops@example.com --subject="DNS hourly digest" \
 *       --since="2026-04-25 00:00:00"
 *
 * Options:
 *   --since=DATETIME    Only include changes after this UTC timestamp
 *                       (default: 24 hours ago).
 *   --until=DATETIME    Optional upper bound (default: now).
 *   --to=ADDR[,ADDR]    Required. Recipient(s); comma-separated.
 *   --from=ADDR         Sender override (default: dns.hostmaster from config).
 *   --subject=TEXT      Required. Subject line.
 *   --header=HTML       Optional preamble inserted before the table.
 *   --footer=HTML       Optional postamble inserted after the table.
 *   --dry-run           Print the rendered HTML to stdout instead of sending.
 *   --help              Show this help.
 */

require __DIR__ . '/../vendor/autoload.php';

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Domain\Service\DatabaseCredentialMapper;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;

$options = getopt('', [
    'since::',
    'until::',
    'to:',
    'from::',
    'subject:',
    'header::',
    'footer::',
    'dry-run',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php send_record_changes_email.php --to=ADDR --subject=TEXT [options]\n");
    fwrite(STDOUT, "See file header for full option list.\n");
    exit(0);
}

if (empty($options['to']) || empty($options['subject'])) {
    fwrite(STDERR, "Error: --to and --subject are required. Use --help for usage.\n");
    exit(2);
}

$dryRun = isset($options['dry-run']);
$since = $options['since'] ?? (new DateTimeImmutable('-1 day', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$until = $options['until'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
    fwrite(STDERR, "Error: --since must be 'YYYY-MM-DD HH:MM:SS'.\n");
    exit(2);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $until)) {
    fwrite(STDERR, "Error: --until must be 'YYYY-MM-DD HH:MM:SS'.\n");
    exit(2);
}

$config = ConfigurationManager::getInstance();
$config->initialize();

$db = (new DatabaseService(new PDODatabaseConnection()))
    ->connect(DatabaseCredentialMapper::mapCredentials($config));

$logger = new RecordChangeLogger($db);
$rows = $logger->getFiltered(
    ['date_from' => $since, 'date_to' => $until],
    100000,
    0
);

if ($rows === []) {
    if ($dryRun) {
        fwrite(STDOUT, "(no changes between $since and $until)\n");
    }
    exit(0);
}

$html = renderDigestHtml($rows, $since, $until, $options['header'] ?? '', $options['footer'] ?? '');

if ($dryRun) {
    fwrite(STDOUT, $html);
    exit(0);
}

$recipients = array_filter(array_map('trim', explode(',', $options['to'])));
if ($recipients === []) {
    fwrite(STDERR, "Error: --to has no valid addresses.\n");
    exit(2);
}

$mailService = new MailService($config);
$headers = [];
if (!empty($options['from'])) {
    $headers['From'] = $options['from'];
}

$failed = 0;
foreach ($recipients as $recipient) {
    $ok = $mailService->sendMail($recipient, $options['subject'], $html, '', $headers);
    if (!$ok) {
        $failed++;
        fwrite(STDERR, "Failed to send to $recipient\n");
    }
}

exit($failed > 0 ? 1 : 0);

function renderDigestHtml(array $rows, string $since, string $until, string $headerHtml, string $footerHtml): string
{
    $count = count($rows);
    $h = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;font-size:13px;color:#333;">';

    if ($headerHtml !== '') {
        $h .= $headerHtml;
    }

    $h .= '<h2 style="margin:0 0 8px 0;">Poweradmin record change digest</h2>';
    $h .= '<p style="margin:0 0 12px 0;color:#666;">' . $count . ' change' . ($count === 1 ? '' : 's')
        . ' between ' . htmlspecialchars($since) . ' UTC and ' . htmlspecialchars($until) . ' UTC.</p>';

    $h .= '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;">';
    $h .= '<thead><tr style="background:#f0f0f0;border-bottom:2px solid #ccc;">';
    $h .= '<th align="left">When</th><th align="left">Action</th><th align="left">User</th>'
        . '<th align="left">Zone</th><th align="left">Name</th><th align="left">Type</th>'
        . '<th align="left">Content</th><th align="left">TTL</th><th align="left">Prio</th></tr></thead><tbody>';

    foreach ($rows as $row) {
        $h .= renderRowHtml($row);
    }
    $h .= '</tbody></table>';

    if ($footerHtml !== '') {
        $h .= $footerHtml;
    }

    $h .= '</body></html>';
    return $h;
}

function renderRowHtml(array $row): string
{
    $action = $row['action'] ?? '';
    $before = $row['before_state_decoded'] ?? null;
    $after = $row['after_state_decoded'] ?? null;
    $changed = $row['changed_fields'] ?? [];

    $green = 'background:#d4edda;';
    $red = 'background:#f8d7da;';
    $yellow = 'background:#fff3cd;';

    if ($action === 'record_edit' && is_array($before) && is_array($after)) {
        $cell = function (string $field, $value) use ($changed): string {
            $bold = in_array($field, $changed, true) ? 'font-weight:bold;' : '';
            return '<td style="' . $bold . '">' . htmlspecialchars((string) ($value ?? '')) . '</td>';
        };
        $when = htmlspecialchars($row['created_at'] ?? '');
        $user = htmlspecialchars($row['username'] ?? '');
        $zone = htmlspecialchars($before['zone_name'] ?? $after['zone_name'] ?? (string) ($row['zone_id'] ?? ''));

        $out = '<tr style="' . $red . '">';
        $out .= '<td rowspan="2">' . $when . '</td>';
        $out .= '<td rowspan="2">' . htmlspecialchars($action) . '</td>';
        $out .= '<td rowspan="2">' . $user . '</td>';
        $out .= '<td rowspan="2">' . $zone . '</td>';
        $out .= $cell('name', $before['name'] ?? null);
        $out .= $cell('type', $before['type'] ?? null);
        $out .= $cell('content', $before['content'] ?? null);
        $out .= $cell('ttl', $before['ttl'] ?? null);
        $out .= $cell('prio', $before['prio'] ?? null);
        $out .= '</tr><tr style="' . $green . '">';
        $out .= $cell('name', $after['name'] ?? null);
        $out .= $cell('type', $after['type'] ?? null);
        $out .= $cell('content', $after['content'] ?? null);
        $out .= $cell('ttl', $after['ttl'] ?? null);
        $out .= $cell('prio', $after['prio'] ?? null);
        $out .= '</tr>';
        return $out;
    }

    $bg = '';
    if (in_array($action, ['record_create', 'zone_create'], true)) {
        $bg = $green;
    } elseif (in_array($action, ['record_delete', 'zone_delete'], true)) {
        $bg = $red;
    } elseif ($action === 'zone_metadata_edit') {
        $bg = $yellow;
    }

    $snap = is_array($after) ? $after : (is_array($before) ? $before : []);
    $when = htmlspecialchars($row['created_at'] ?? '');
    $user = htmlspecialchars($row['username'] ?? '');
    $zone = htmlspecialchars($snap['zone_name'] ?? $snap['name'] ?? (string) ($row['zone_id'] ?? ''));

    $out = '<tr style="' . $bg . '">';
    $out .= '<td>' . $when . '</td>';
    $out .= '<td>' . htmlspecialchars($action) . '</td>';
    $out .= '<td>' . $user . '</td>';
    $out .= '<td>' . $zone . '</td>';

    if (str_starts_with($action, 'zone_')) {
        $details = [];
        if (!empty($snap['type'])) {
            $details[] = 'type=' . $snap['type'];
        }
        if (!empty($snap['master'])) {
            $details[] = 'master=' . $snap['master'];
        }
        if (!empty($snap['template_id'])) {
            $details[] = 'template=' . $snap['template_id'];
        }
        if (isset($snap['record_count'])) {
            $details[] = 'records=' . $snap['record_count'];
        }
        $out .= '<td colspan="5">' . htmlspecialchars(implode(' ', $details)) . '</td>';
    } else {
        $out .= '<td>' . htmlspecialchars((string) ($snap['name'] ?? '')) . '</td>';
        $out .= '<td>' . htmlspecialchars((string) ($snap['type'] ?? '')) . '</td>';
        $out .= '<td>' . htmlspecialchars((string) ($snap['content'] ?? '')) . '</td>';
        $out .= '<td>' . htmlspecialchars((string) ($snap['ttl'] ?? '')) . '</td>';
        $out .= '<td>' . htmlspecialchars((string) ($snap['prio'] ?? '')) . '</td>';
    }
    $out .= '</tr>';
    return $out;
}
