<?php

namespace App\Database\Migrations;

use App\Database\BaseMigration;

/**
 * Knowledge base content split into chunks (retrieval + citations), file metadata on
 * knowledge_bases, and the citations a task result relied on (agent_tasks.sources).
 */
class CreateKnowledgeChunks extends BaseMigration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => $this->id(),
            'tenant_id'         => $this->fk(),
            'knowledge_base_id' => $this->fk(),
            'seq'               => $this->int(0),
            'content'           => $this->text(false),
            'char_count'        => $this->int(0),
            'created_at'        => $this->datetime(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['knowledge_base_id', 'seq']);
        $this->forge->addKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('knowledge_base_id', 'knowledge_bases', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('knowledge_chunks', true, $this->tableAttributes());

        $this->forge->addColumn('knowledge_bases', [
            'original_filename' => $this->str(255, true),
            'mime_type'         => $this->str(100, true),
            'size_bytes'        => $this->int(0),
            'char_count'        => $this->int(0),
            'error_message'     => $this->str(255, true),
        ]);
        $this->forge->addColumn('agent_tasks', ['sources' => $this->json()]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('agent_tasks', 'sources');
        $this->forge->dropColumn('knowledge_bases', ['original_filename', 'mime_type', 'size_bytes', 'char_count', 'error_message']);
        $this->forge->dropTable('knowledge_chunks', true);
    }
}
