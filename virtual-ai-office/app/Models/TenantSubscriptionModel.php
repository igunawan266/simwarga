<?php

namespace App\Models;

class TenantSubscriptionModel extends TenantScopedModel
{
    public const USABLE_STATUSES = ['trial', 'active'];

    protected $table         = 'tenant_subscriptions';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['tenant_id', 'plan_id', 'office_id', 'status', 'starts_at', 'ends_at', 'auto_renew', 'price_snapshot', 'limits_snapshot', 'cancelled_at'];

    /**
     * The subscription currently granting access, if any. past_due keeps access during the
     * grace period; the lifecycle cron moves it to expired when the grace period ends.
     */
    public function current(): ?array
    {
        return $this->select('tenant_subscriptions.*, subscription_plans.code AS plan_code, subscription_plans.name AS plan_name, subscription_plans.max_agents, subscription_plans.max_operational_rooms')
            ->join('subscription_plans', 'subscription_plans.id = tenant_subscriptions.plan_id')
            ->groupStart()
                ->groupStart()
                    ->whereIn('tenant_subscriptions.status', self::USABLE_STATUSES)
                    ->where('tenant_subscriptions.ends_at >', date('Y-m-d H:i:s'))
                ->groupEnd()
                ->orWhere('tenant_subscriptions.status', 'past_due')
            ->groupEnd()
            ->orderBy('tenant_subscriptions.ends_at', 'DESC')
            ->first();
    }
}
