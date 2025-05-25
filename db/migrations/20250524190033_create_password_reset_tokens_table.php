<?php

use Phinx\Migration\AbstractMigration;

class CreatePasswordResetTokensTable extends AbstractMigration
{
    /**
     * Create the password_reset_tokens table
     */
    public function change(): void
    {
        $table = $this->table('password_reset_tokens');
        $table->addColumn('email', 'string', ['limit' => 255])
              ->addColumn('token', 'string', ['limit' => 64])
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('expires_at', 'timestamp')
              ->addColumn('used', 'boolean', ['default' => false])
              ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
              ->addIndex(['email'])
              ->addIndex(['token'], ['unique' => true])
              ->addIndex(['expires_at'])
              ->create();
    }
}
