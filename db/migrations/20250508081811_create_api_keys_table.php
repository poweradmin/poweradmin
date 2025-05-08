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

final class CreateApiKeysTable extends AbstractMigration
{
    public function change(): void
    {
        $adapter = $this->getAdapter();
        $adapterType = $adapter->getAdapterType();

        // Create api_keys table
        $table = $this->table('api_keys', ['id' => true, 'primary_key' => 'id']);

        // Common columns for all database types
        $table
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('secret_key', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addColumn('disabled', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('expires_at', 'datetime', ['null' => true])
            ->addIndex(['secret_key'], ['unique' => true])
            ->addIndex(['created_by'])
            ->addIndex(['disabled']);

        // Apply database-specific settings
        switch ($adapterType) {
            case 'mysql':
                // MySQL specific column formats
                $table->changeColumn('created_at', 'datetime', [
                    'null' => false,
                    'default' => 'CURRENT_TIMESTAMP'
                ]);
                break;

            case 'pgsql':
                // PostgreSQL specific column formats
                $table->changeColumn('disabled', 'boolean', [
                    'null' => false,
                    'default' => false
                ]);
                break;

            case 'sqlite':
                // SQLite specific column formats
                // SQLite has built-in CURRENT_TIMESTAMP support
                break;

            default:
                throw new RuntimeException("Unsupported database adapter: $adapterType");
        }

        $table->create();

        // Add foreign key to users table
        if (in_array($adapterType, ['mysql', 'pgsql'])) {
            $table->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET NULL',
                'update' => 'CASCADE'
            ])->save();
        }
    }
}
