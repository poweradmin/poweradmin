<?php

use Phinx\Migration\AbstractMigration;

class CreateUserPreferencesTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('user_preferences');

        $table->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('preference_key', 'string', ['limit' => 100])
            ->addColumn('preference_value', 'text', ['null' => true])
            ->addIndex(['user_id', 'preference_key'], ['unique' => true])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();
    }
}
