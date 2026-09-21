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

/*
 * Rewrites the seed INSERT blocks of sql/poweradmin-*-db-structure.sql from
 * Poweradmin\Domain\Model\PermissionCatalogue. Each block sits between
 * "-- BEGIN generated seed: <table> (composer sql:seed)" and
 * "-- END generated seed: <table>"; everything else in the files is left alone.
 * Pass --check to exit non-zero instead of writing when a file is out of date.
 */

declare(strict_types=1);

use Poweradmin\Domain\Model\PermissionCatalogue;

require dirname(__DIR__) . '/vendor/autoload.php';

$check = in_array('--check', $argv, true);
$sqlDir = dirname(__DIR__) . '/sql';

$dialects = [
    'mysql' => ['identifier' => '`', 'oneStatementPerRow' => false, 'quoteGroupIdentifiers' => true],
    'pgsql' => ['identifier' => '"', 'oneStatementPerRow' => false, 'quoteGroupIdentifiers' => false],
    'sqlite' => ['identifier' => '"', 'oneStatementPerRow' => true, 'quoteGroupIdentifiers' => false],
];

$quoteValue = static fn(int|string|null $v): string => match (true) {
    $v === null => 'NULL',
    is_int($v) => (string)$v,
    default => "'" . str_replace("'", "''", $v) . "'",
};

$blocks = static function (array $dialect) use ($quoteValue): array {
    $q = $dialect['identifier'];
    $ident = static fn(string $name) => $q . $name . $q;
    $groupIdent = $dialect['quoteGroupIdentifiers'] ? $ident : static fn(string $name) => $name;
    $tuple = static fn(array $row, string $sep) => '(' . implode($sep, array_map($quoteValue, $row)) . ')';

    $templates = array_map(
        static fn(array $t) => [$t['id'], $t['name'], $t['descr'], $t['template_type']],
        PermissionCatalogue::templates()
    );
    $groups = array_map(
        static fn(array $g) => [$g['id'], $g['name'], $g['description'], PermissionCatalogue::templateId($g['template']), null],
        PermissionCatalogue::groups()
    );

    // Indents and separators reproduce the historical layout of the files so the regenerated blocks stay diffable.
    return [
        'perm_items' => [
            'header' => 'INSERT INTO ' . $ident('perm_items') . ' (' . implode(', ', array_map($ident, ['id', 'name', 'descr'])) . ') VALUES',
            'rows' => array_map(static fn(array $r) => $tuple($r, ",\t"), PermissionCatalogue::permissions()),
            'indent' => str_repeat(' ', 53),
            'oneStatementPerRow' => $dialect['oneStatementPerRow'],
        ],
        'perm_templ' => [
            'header' => 'INSERT INTO ' . $ident('perm_templ') . ' (' . implode(', ', array_map($ident, ['id', 'name', 'descr', 'template_type'])) . ') VALUES',
            'rows' => array_map(static fn(array $r) => $tuple($r, ",\t"), $templates),
            'indent' => '    ',
            'oneStatementPerRow' => $dialect['oneStatementPerRow'],
        ],
        'perm_templ_items' => [
            'header' => 'INSERT INTO ' . $ident('perm_templ_items') . ' (' . implode(', ', array_map($ident, ['id', 'templ_id', 'perm_id'])) . ') VALUES',
            'rows' => array_map(static fn(array $r) => $tuple($r, ",\t"), PermissionCatalogue::templateItems()),
            'indent' => '    ',
            'oneStatementPerRow' => $dialect['oneStatementPerRow'],
        ],
        'user_groups' => [
            'header' => 'INSERT INTO ' . $groupIdent('user_groups') . ' (' . implode(', ', array_map($groupIdent, ['id', 'name', 'description', 'perm_templ', 'created_by'])) . ') VALUES',
            'rows' => array_map(static fn(array $r) => $tuple($r, ', '), $groups),
            'indent' => '    ',
            'oneStatementPerRow' => false,
        ],
    ];
};

$render = static function (array $block): string {
    if ($block['oneStatementPerRow']) {
        return implode("\n", array_map(static fn(string $row) => $block['header'] . ' ' . $row . ';', $block['rows']));
    }

    return $block['header'] . "\n" . $block['indent'] . implode(",\n" . $block['indent'], $block['rows']) . ';';
};

$stale = [];
foreach ($dialects as $name => $dialect) {
    $path = "$sqlDir/poweradmin-$name-db-structure.sql";
    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read $path\n");
        exit(1);
    }

    $updated = $sql;
    foreach ($blocks($dialect) as $table => $block) {
        $begin = "-- BEGIN generated seed: $table (composer sql:seed)\n";
        $end = "\n-- END generated seed: $table";
        $pattern = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '/s';
        if (!preg_match($pattern, $updated)) {
            fwrite(STDERR, "Marker lines for $table are missing in $path\n");
            exit(1);
        }
        $replacement = $begin . $render($block) . $end;
        $updated = preg_replace_callback($pattern, static fn() => $replacement, $updated, 1);
    }

    if ($updated === $sql) {
        continue;
    }
    $stale[] = $path;
    if (!$check) {
        file_put_contents($path, $updated);
        echo "Regenerated seed blocks in $path\n";
    }
}

if ($check && $stale !== []) {
    fwrite(STDERR, "Seed blocks are out of date in:\n  " . implode("\n  ", $stale) . "\nRun: composer sql:seed\n");
    exit(1);
}
