<?php

namespace App\Services\AI;

use Config\Database;

/** Append-only message log between users and agents, and between agents (agent_conversations). */
final class AgentConversation
{
    public static function add(
        int $tenantId,
        int $taskId,
        string $senderType,
        string $content,
        ?int $senderUserId = null,
        ?int $senderAgentId = null,
        ?int $recipientAgentId = null,
        int $tokens = 0,
    ): void {
        Database::connect()->table('agent_conversations')->insert([
            'tenant_id' => $tenantId, 'thread_id' => 'task-' . $taskId, 'task_id' => $taskId, 'sender_type' => $senderType,
            'sender_user_id' => $senderUserId, 'sender_agent_id' => $senderAgentId, 'recipient_agent_id' => $recipientAgentId,
            'content' => $content, 'tokens' => $tokens, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
