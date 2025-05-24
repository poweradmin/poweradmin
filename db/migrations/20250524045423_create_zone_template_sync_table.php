<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateZoneTemplateSyncTable extends AbstractMigration
{
    /**
     * Create zone_template_sync table to track sync status between templates and zones
     */
    public function change(): void
    {
        $table = $this->table('zone_template_sync');

        $table->addColumn('zone_id', 'integer', ['null' => false, 'comment' => 'Zone ID'])
              ->addColumn('zone_templ_id', 'integer', ['null' => false, 'comment' => 'Zone template ID'])
              ->addColumn('last_synced', 'timestamp', ['null' => true, 'default' => null, 'comment' => 'Last sync timestamp'])
              ->addColumn('template_last_modified', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP', 'comment' => 'Template last modification time'])
              ->addColumn('needs_sync', 'boolean', ['null' => false, 'default' => false, 'comment' => 'Whether zone needs sync'])
              ->addColumn('created_at', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('updated_at', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['zone_id', 'zone_templ_id'], ['unique' => true, 'name' => 'idx_zone_template_unique'])
              ->addIndex(['zone_templ_id'], ['name' => 'idx_zone_templ_id'])
              ->addIndex(['needs_sync'], ['name' => 'idx_needs_sync'])
              ->addForeignKey('zone_id', 'domains', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
              ->addForeignKey('zone_templ_id', 'zone_templ', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
              ->create();
    }
}
