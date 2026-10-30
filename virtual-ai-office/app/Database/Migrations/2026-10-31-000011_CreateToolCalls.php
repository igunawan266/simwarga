<?php

namespace App\Database\Migrations;

use App\Database\BaseMigration;

/**
 * Tool use by agents: approval mode per tool, persisted conversation state for multi-step
 * tasks (so a task can pause for human approval and resume in another worker process),
 * and one row per tool call with its decision and outcome.
 */
class CreateToolCalls extends BaseMigration
{
    public function up(): void
    {
        $this->forge->addColumn('ai_tools', ['approval_mode' => $this->str(20, false, 'approval')]); // approval | auto
        $this->forge->addColumn('agent_tasks', [
            'state'       => ['type' => 'LONGTEXT', 'null' => true], // JSON: system, messages (verbatim), documents, model, effort
            'tool_rounds' => $this->int(0),
        ]);

        $this->forge->addField([
            'id'                 => $this->id(),
            'tenant_id'          => $this->fk(),
            'task_id'            => $this->fk(),
            'agent_id'           => $this->fk(true),
            'tool_id'            => $this->fk(true),
            'round'              => $this->int(0),
            'tool_use_id'        => $this->str(100),
            'tool_name'          => $this->str(64),
            'input'              => $this->json(),
            'status'             => $this->str(20, false, 'pending_approval'), // pending_approval|approved|rejected|executed|failed
            'result'             => $this->text(),
            'is_error'           => $this->bool(0),
            'http_status'        => $this->int(0),
            'duration_ms'        => $this->int(0),
            'decided_by_user_id' => $this->fk(true),
            'decision_note'      => $this->str(255, true),
            'decided_at'         => $this->datetime(),
            'executed_at'        => $this->datetime(),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['task_id', 'tool_use_id']);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('task_id', 'agent_tasks', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('agent_id', 'ai_agents', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('tool_id', 'ai_tools', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('decided_by_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('tool_calls', true, $this->tableAttributes());
    }

    public function down(): void
    {
        $this->forge->dropTable('tool_calls', true);
        $this->forge->dropColumn('agent_tasks', ['state', 'tool_rounds']);
        $this->forge->dropColumn('ai_tools', 'approval_mode');
    }
}
