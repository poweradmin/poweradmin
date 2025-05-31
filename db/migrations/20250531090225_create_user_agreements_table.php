<?php

use Phinx\Migration\AbstractMigration;

class CreateUserAgreementsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('user_agreements');

        $table->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('agreement_version', 'string', ['limit' => 50])
            ->addColumn('accepted_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'text', ['null' => true])
            ->addIndex(['user_id', 'agreement_version'], [
                'unique' => true,
                'name' => 'unique_user_agreement'
            ])
            ->addIndex(['user_id'])
            ->addIndex(['agreement_version'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();
    }
}
