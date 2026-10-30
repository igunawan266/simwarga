<?php

namespace App\Services;

use App\Models\NotificationModel;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * In-app notifications per tenant. "billing" notifications are visible to owners/admins only.
 * When SMTP is configured (Config\Email via .env) the same message is e-mailed to the
 * tenant's active owners/admins; delivery failures never break the business flow.
 */
class NotificationService
{
    public const BILLING_ROLES = ['tenant_owner', 'tenant_admin'];

    /** Platform-side write with an explicit tenant id (works from cron and webhooks). */
    public function notify(int $tenantId, string $type, string $title, string $body, array $data = [], string $audience = 'billing'): void
    {
        Database::connect()->table('notifications')->insert([
            'tenant_id' => $tenantId, 'audience' => $audience, 'type' => $type, 'title' => mb_substr($title, 0, 150),
            'body' => $body, 'data' => json_encode($data), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->email($tenantId, $title, $body, $audience);
    }

    /** Current member's notifications (tenant scoped). */
    public function forCurrentMember(int $limit = 30): array
    {
        $model = model(NotificationModel::class);
        if (! Services::tenantContext()->hasRole(...self::BILLING_ROLES)) {
            $model->where('audience', 'all');
        }
        $rows = $model->orderBy('id', 'DESC')->findAll($limit);

        return array_map(static fn (array $n) => [
            'id' => (int) $n['id'], 'type' => $n['type'], 'title' => $n['title'], 'body' => $n['body'],
            'data' => json_decode($n['data'] ?? '{}', true), 'read' => $n['read_at'] !== null, 'created_at' => $n['created_at'],
        ], $rows);
    }

    public function markAllRead(): void
    {
        $model = model(NotificationModel::class);
        if (! Services::tenantContext()->hasRole(...self::BILLING_ROLES)) {
            $model->where('audience', 'all');
        }
        $model->where('read_at', null)->set(['read_at' => date('Y-m-d H:i:s')])->update();
    }

    private function email(int $tenantId, string $subject, string $body, string $audience): void
    {
        $config = config('Email');
        if ($config->protocol !== 'smtp' || $config->SMTPHost === '' || $config->fromEmail === '') {
            return; // e-mail not configured: in-app only
        }
        $q = Database::connect()->table('tenant_members m')->select('u.email')
            ->join('users u', 'u.id = m.user_id')->join('roles r', 'r.id = m.role_id')
            ->where('m.tenant_id', $tenantId)->where('m.status', 'active')->where('u.status', 'active');
        if ($audience === 'billing') {
            $q->whereIn('r.slug', self::BILLING_ROLES);
        }
        foreach (array_column($q->get()->getResultArray(), 'email') as $to) {
            try {
                $mail = Services::email(null, false);
                $mail->setTo($to)->setSubject('[Virtual AI Office] ' . $subject)->setMessage($body);
                $mail->send(false);
            } catch (Throwable $e) {
                log_message('error', 'Notification e-mail to {to} failed: {msg}', ['to' => $to, 'msg' => $e->getMessage()]);
            }
        }
    }
}
