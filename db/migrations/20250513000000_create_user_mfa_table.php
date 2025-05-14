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

use Phinx\Migration\AbstractMigration;

final class CreateUserMfaTable extends AbstractMigration
{
    public function change(): void
    {
        $adapter = $this->getAdapter();
        $adapterType = $adapter->getAdapterType();

        // Create user_mfa table
        $table = $this->table('user_mfa', ['id' => true, 'primary_key' => 'id']);

        // Common columns for all database types
        $table
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('enabled', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('secret', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('recovery_codes', 'text', ['null' => true])
            ->addColumn('type', 'string', ['limit' => 20, 'null' => false, 'default' => 'app'])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('verification_data', 'text', ['null' => true])
            ->addIndex(['user_id'], ['unique' => true])
            ->addIndex(['enabled']);

        // Apply database-specific settings
        switch ($adapterType) {
            case 'mysql':
                // MySQL specific column formats
                $table->changeColumn('created_at', 'datetime', [
                    'null' => false,
                    'default' => 'CURRENT_TIMESTAMP'
                ]);
                $table->changeColumn('updated_at', 'datetime', [
                    'null' => true,
                    'default' => null,
                    'update' => 'CURRENT_TIMESTAMP'
                ]);
                break;

            case 'pgsql':
                // PostgreSQL specific column formats
                $table->changeColumn('enabled', 'boolean', [
                    'null' => false,
                    'default' => false
                ]);
                break;

            case 'sqlite':
                // SQLite specific column formats
                $table->changeColumn('enabled', 'integer', [
                    'null' => false,
                    'default' => 0,
                    'limit' => 1
                ]);
                break;

            default:
                throw new RuntimeException("Unsupported database adapter: $adapterType");
        }

        $table->create();

        // Add foreign key to users table
        if (in_array($adapterType, ['mysql', 'pgsql'])) {
            $table->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE'
            ])->save();
        }
    }
}
