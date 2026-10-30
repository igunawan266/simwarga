<?php

namespace App\Database\Migrations;

use App\Database\BaseMigration;

/**
 * ai_models, ai_tools, knowledge_bases, ai_agents, ai_agent_tools,
 * agent_tasks, agent_logs, agent_conversations
 */
class CreateAiTables extends BaseMigration
{
    public function up(): void
    {
        // Platform catalogue of LLMs a tenant may pick from.
        $this->forge->addField([
            'id'             => $this->id(),
            'provider'       => $this->str(50),  // anthropic|openai|google|custom
            'model_key'      => $this->str(100), // provider model id
            'display_name'   => $this->str(100),
            'context_window' => $this->int(0),
            'input_price_per_mtok'  => ['type' => 'DECIMAL', 'constraint' => '12,4', 'default' => 0],
            'output_price_per_mtok' => ['type' => 'DECIMAL', 'constraint' => '12,4', 'default' => 0],
            'supports_tools' => $this->bool(1),
            'is_active'      => $this->bool(1),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['provider', 'model_key']);
        $this->forge->createTable('ai_models', true, $this->tableAttributes());

        // tenant_id NULL = platform tool available to everyone; otherwise tenant-private tool.
        $this->forge->addField([
            'id'           => $this->id(),
            'tenant_id'    => $this->fk(true),
            'slug'         => $this->str(80),
            'name'         => $this->str(100),
            'description'  => $this->text(),
            'tool_type'    => $this->str(30, false, 'builtin'), // builtin|http|webhook
            'input_schema' => $this->json(),
            'config'       => $this->json(), // never holds plaintext secrets; see tenant_settings (encrypted)
            'is_active'    => $this->bool(1),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'slug']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ai_tools', true, $this->tableAttributes());

        $this->forge->addField([
            'id'          => $this->id(),
            'tenant_id'   => $this->fk(),
            'name'        => $this->str(150),
            'description' => $this->text(),
            'source_type' => $this->str(20, false, 'text'), // text|file|url
            'content'     => ['type' => 'LONGTEXT', 'null' => true],
            'file_path'   => $this->str(255, true),
            'status'      => $this->str(20, false, 'ready'), // pending|indexing|ready|error
            'chunk_count' => $this->int(0),
            ...$this->timestamps(true),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('knowledge_bases', true, $this->tableAttributes());

        $this->forge->addField([
            'id'             => $this->id(),
            'tenant_id'      => $this->fk(),
            'room_id'        => $this->fk(true),
            'ai_model_id'    => $this->fk(true),
            'knowledge_base_id' => $this->fk(true),
            'manager_agent_id'  => $this->fk(true), // reporting line for multi-agent delegation
            'name'           => $this->str(100),
            'avatar'         => $this->str(255, true),
            'job_title'      => $this->str(100),
            'department'     => $this->str(50),
            'job_description' => $this->text(),
            'system_prompt'  => $this->text(),
            'api_config'     => $this->json(), // temperature, max_tokens... (no secrets)
            'status'         => $this->str(20, false, 'offline'), // online|working|idle|waiting|error|offline
            'is_active'      => $this->bool(0),
            'token_limit_monthly' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'tokens_used_month'   => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'tokens_used_total'   => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'last_active_at' => $this->datetime(),
            ...$this->timestamps(true),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey(['tenant_id', 'room_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('room_id', 'rooms', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('ai_model_id', 'ai_models', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('knowledge_base_id', 'knowledge_bases', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('manager_agent_id', 'ai_agents', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('ai_agents', true, $this->tableAttributes());

        $this->forge->addField([
            'id'         => $this->id(),
            'tenant_id'  => $this->fk(),
            'agent_id'   => $this->fk(),
            'tool_id'    => $this->fk(),
            'created_at' => $this->datetime(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['agent_id', 'tool_id']);
        $this->forge->addKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('agent_id', 'ai_agents', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('tool_id', 'ai_tools', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ai_agent_tools', true, $this->tableAttributes());

        // parent_task_id lets a manager agent split work into sub-tasks for other agents.
        $this->forge->addField([
            'id'              => $this->id(),
            'tenant_id'       => $this->fk(),
            'parent_task_id'  => $this->fk(true),
            'agent_id'        => $this->fk(true),
            'assigned_by_user_id'  => $this->fk(true),
            'assigned_by_agent_id' => $this->fk(true),
            'title'           => $this->str(200),
            'instruction'     => $this->text(),
            'priority'        => $this->str(10, false, 'normal'), // low|normal|high|urgent
            'status'          => $this->str(20, false, 'queued'), // queued|running|waiting|completed|failed|cancelled
            'result'          => ['type' => 'LONGTEXT', 'null' => true],
            'error_message'   => $this->text(),
            'input_tokens'    => $this->int(0),
            'output_tokens'   => $this->int(0),
            'attempts'        => $this->int(0),
            'queued_at'       => $this->datetime(),
            'started_at'      => $this->datetime(),
            'completed_at'    => $this->datetime(),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey(['tenant_id', 'agent_id']);
        $this->forge->addKey('parent_task_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('parent_task_id', 'agent_tasks', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('agent_id', 'ai_agents', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('assigned_by_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('assigned_by_agent_id', 'ai_agents', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('agent_tasks', true, $this->tableAttributes());

        // Execution trace: status changes, tool calls, LLM calls, errors.
        $this->forge->addField([
            'id'          => $this->id(),
            'tenant_id'   => $this->fk(),
            'agent_id'    => $this->fk(true),
            'task_id'     => $this->fk(true),
            'level'       => $this->str(10, false, 'info'), // debug|info|warning|error
            'event'       => $this->str(50), // status_changed|llm_call|tool_call|delegation|error
            'message'     => $this->text(),
            'context'     => $this->json(),
            'input_tokens'  => $this->int(0),
            'output_tokens' => $this->int(0),
            'duration_ms'   => $this->int(0),
            'created_at'  => $this->datetime(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'created_at']);
        $this->forge->addKey(['agent_id', 'created_at']);
        $this->forge->addKey('task_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('agent_id', 'ai_agents', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('task_id', 'agent_tasks', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('agent_logs', true, $this->tableAttributes());

        // Messages between user<->agent and agent<->agent, grouped by thread_id.
        $this->forge->addField([
            'id'              => $this->id(),
            'tenant_id'       => $this->fk(),
            'thread_id'       => $this->str(36),
            'task_id'         => $this->fk(true),
            'sender_type'     => $this->str(10), // user|agent|system|tool
            'sender_user_id'  => $this->fk(true),
            'sender_agent_id' => $this->fk(true),
            'recipient_agent_id' => $this->fk(true),
            'content'         => ['type' => 'LONGTEXT', 'null' => true],
            'metadata'        => $this->json(),
            'tokens'          => $this->int(0),
            'created_at'      => $this->datetime(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'thread_id']);
        $this->forge->addKey('task_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('task_id', 'agent_tasks', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('sender_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('sender_agent_id', 'ai_agents', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('recipient_agent_id', 'ai_agents', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('agent_conversations', true, $this->tableAttributes());
    }

    public function down(): void
    {
        foreach (['agent_conversations', 'agent_logs', 'agent_tasks', 'ai_agent_tools', 'ai_agents', 'knowledge_bases', 'ai_tools', 'ai_models'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
