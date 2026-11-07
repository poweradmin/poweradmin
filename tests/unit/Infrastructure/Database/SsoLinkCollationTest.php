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

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PHPUnit\Framework\TestCase;

/**
 * The installer must build the SSO link identifier columns with the same
 * binary collation as the shipped MySQL structure file.
 */
class SsoLinkCollationTest extends TestCase
{
    public function testInstallerColumnsAreBinaryLikeTheSqlFile(): void
    {
        require_once dirname(__DIR__, 4) . '/install/helpers/DatabaseStructureHelper.php';
        $sql = (string)file_get_contents(dirname(__DIR__, 4) . '/sql/poweradmin-mysql-db-structure.sql');

        $expected = [
            'oidc_user_links' => ['provider_id', 'oidc_subject'],
            'saml_user_links' => ['provider_id', 'saml_subject'],
        ];
        $seen = 0;
        foreach (\PoweradminInstall\DatabaseStructureHelper::getDefaultTables() as $table) {
            foreach ($expected[$table['table_name']] ?? [] as $column) {
                $this->assertSame('utf8mb4_bin', $table['fields'][$column]['collation'] ?? null, "{$table['table_name']}.$column");
                $this->assertMatchesRegularExpression('/`' . $column . '` VARCHAR\(\d+\) NOT NULL COLLATE utf8mb4_bin/', $sql);
                $seen++;
            }
        }
        $this->assertSame(4, $seen);
    }
}
