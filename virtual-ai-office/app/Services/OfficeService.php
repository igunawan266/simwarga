<?php

namespace App\Services;

use App\Models\AgentLogModel;
use App\Models\AgentTaskModel;
use App\Models\AiAgentModel;
use App\Models\OfficeModel;
use App\Models\RoomModel;
use App\Models\TenantSubscriptionModel;
use Config\Services;

/**
 * Everything the tenant's own 3D office needs. All queries run inside the verified
 * TenantContext, so a tenant can only ever receive its own rows.
 */
class OfficeService
{
    public function overview(): ?array
    {
        $ctx    = Services::tenantContext();
        $tenant = $ctx->tenant();
        $office = model(OfficeModel::class)->findForTenant($ctx->id());
        if ($office === null) {
            return null;
        }

        $rooms  = model(RoomModel::class)->withType()->where('rooms.office_id', $office['id'])->findAll();
        $agents = model(AiAgentModel::class)->publicColumns()->orderBy('ai_agents.id')->findAll();

        $byRoom = [];
        $status = array_fill_keys(AiAgentModel::STATUSES, 0);
        foreach ($agents as $a) {
            $byRoom[(int) $a['room_id']] = ($byRoom[(int) $a['room_id']] ?? 0) + 1;
            $status[$a['status']]        = ($status[$a['status']] ?? 0) + 1;
        }

        $subscription = model(TenantSubscriptionModel::class)->current();

        return [
            'tenant' => [
                'name'     => $tenant['company_name'],
                'slug'     => $tenant['slug'],
                'color'    => $tenant['brand_color'],
                'industry' => $tenant['industry'],
                'logo_url' => $tenant['logo_url'],
                'profile'  => $tenant['business_profile'],
                'role'     => $ctx->role(),
            ],
            'office' => [
                'number'       => $office['office_number'],
                'name'         => $office['name'],
                'floor_number' => (int) $office['floor_number'],
                'floor_name'   => $office['floor_name'],
                'area_sqm'     => (int) $office['area_sqm'],
            ],
            'subscription' => $subscription === null ? null : [
                'plan'       => $subscription['plan_name'],
                'plan_code'  => $subscription['plan_code'],
                'status'     => $subscription['status'],
                'ends_at'    => $subscription['ends_at'],
                'max_agents' => (int) $subscription['max_agents'],
            ],
            'rooms' => array_map(static fn (array $r) => [
                'id'          => (int) $r['id'],
                'name'        => $r['name'],
                'type'        => $r['type_slug'],
                'category'    => $r['category'],
                'icon'        => $r['icon'],
                'enabled'     => (bool) $r['is_enabled'],
                'position'    => json_decode($r['position'] ?? 'null', true),
                'agent_count' => $byRoom[(int) $r['id']] ?? 0,
            ], $rooms),
            'agents' => array_map(static fn (array $a) => [
                'id'         => (int) $a['id'],
                'room_id'    => $a['room_id'] === null ? null : (int) $a['room_id'],
                'name'       => $a['name'],
                'job_title'  => $a['job_title'],
                'department' => $a['department'],
                'status'     => $a['status'],
                'is_active'  => (bool) $a['is_active'],
            ], $agents),
            'stats' => [
                'agents_total' => count($agents),
                'by_status'    => $status,
                'tasks_open'   => model(AgentTaskModel::class)->whereIn('status', ['queued', 'running', 'waiting', 'waiting_approval'])->countAllResults(),
            ],
        ];
    }

    public function room(int $roomId): ?array
    {
        $room = model(RoomModel::class)->withType()->find($roomId);
        if ($room === null) {
            return null; // not found OR belongs to another tenant — indistinguishable on purpose
        }

        $agents   = model(AiAgentModel::class)->publicColumns()->where('ai_agents.room_id', $roomId)->findAll();
        $agentIds = array_map(static fn ($a) => (int) $a['id'], $agents);

        $tasks = $logs = [];
        if ($agentIds !== []) {
            $tasks = model(AgentTaskModel::class)->select('id, agent_id, title, status, priority, created_at, completed_at')
                ->whereIn('agent_id', $agentIds)->orderBy('id', 'DESC')->findAll(10);
            $logs = model(AgentLogModel::class)->select('id, agent_id, level, event, message, created_at')
                ->whereIn('agent_id', $agentIds)->orderBy('id', 'DESC')->findAll(15);
        }

        return [
            'room' => [
                'id'          => (int) $room['id'],
                'name'        => $room['name'],
                'type'        => $room['type_slug'],
                'category'    => $room['category'],
                'description' => $room['description'],
                'enabled'     => (bool) $room['is_enabled'],
            ],
            'agents' => $agents,
            'recent_tasks' => $tasks,
            'recent_logs'  => $logs,
        ];
    }
}
