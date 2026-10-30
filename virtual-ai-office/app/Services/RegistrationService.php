<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use Config\Database;
use Config\Services;

/**
 * Self-service tenant registration.
 *
 * One database transaction creates: tenant (pending) + owner account + owner membership +
 * reservation of the chosen office + pending subscription + unpaid invoice + rooms.
 * Nothing is activated here: the subscription becomes active only after a verified
 * payment callback (Phase 4). If the invoice is not paid before the reservation expires,
 * ReservationService releases the office.
 */
class RegistrationService
{
    public function register(array $input, ?int $existingUserId = null): array
    {
        $db   = Database::connect();
        $plan = $db->table('subscription_plans')->where('code', strtoupper((string) $input['plan_code']))
            ->where('is_active', 1)->where('deleted_at', null)->get()->getRowArray();
        if ($plan === null) {
            throw new DomainRuleException('Paket tidak tersedia.');
        }
        if ((int) $plan['is_custom'] === 1) {
            throw new DomainRuleException('Paket ini memakai harga kontrak. Hubungi tim kami untuk penawaran.');
        }

        $office = $db->table('offices o')->select('o.*, f.floor_number')
            ->join('floors f', 'f.id = o.floor_id')
            ->where('f.floor_number', (int) $input['floor_number'])->where('f.floor_type', 'office')
            ->orderBy('o.id')->get()->getRowArray();
        if ($office === null || $office['status'] !== 'available' || $office['tenant_id'] !== null) {
            throw new DomainRuleException('Lantai ini sudah tidak tersedia. Silakan pilih lantai lain.', 409);
        }

        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        if ($existingUserId === null && $db->table('users')->where('email', $email)->countAllResults() > 0) {
            throw new DomainRuleException('Email sudah terdaftar. Silakan login terlebih dahulu, lalu daftarkan perusahaan.', 409);
        }

        $now          = date('Y-m-d H:i:s');
        $dueHours     = (int) SettingsService::get('billing', 'invoice_due_hours', 24);
        $reservedTill = date('Y-m-d H:i:s', strtotime("+{$dueHours} hours"));
        $roleIds      = array_column($db->table('roles')->get()->getResultArray(), 'id', 'slug');

        $db->transException(true)->transStart();

        // 1. Claim the office first — conditional update so two visitors cannot reserve the same floor.
        $db->table('offices')->where('id', $office['id'])->where('status', 'available')->where('tenant_id', null)
            ->update(['status' => 'reserved', 'reserved_until' => $reservedTill, 'updated_at' => $now]);
        if ($db->affectedRows() !== 1) {
            $db->transRollback();
            throw new DomainRuleException('Lantai ini baru saja dipesan orang lain. Silakan pilih lantai lain.', 409);
        }

        // 2. Tenant + owner.
        $tenantId = $this->insert('tenants', [
            'uuid'          => $this->uuid(),
            'slug'          => $this->uniqueSlug($input['company_name']),
            'company_name'  => trim($input['company_name']),
            'industry'      => ($input['industry'] ?? '') ?: null,
            'brand_color'   => ($input['brand_color'] ?? '') ?: '#22d3ee',
            'contact_email' => $existingUserId === null ? $email : null,
            'status'        => 'pending',
            'is_listed'     => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $userId = $existingUserId ?? $this->insert('users', [
            'role_id'       => $roleIds['user'],
            'name'          => trim($input['owner_name']),
            'email'         => $email,
            'password_hash' => password_hash($input['password'], PASSWORD_DEFAULT),
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $this->insert('tenant_members', [
            'tenant_id' => $tenantId, 'user_id' => $userId, 'role_id' => $roleIds['tenant_owner'],
            'status' => 'active', 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('offices')->where('id', $office['id'])->update(['tenant_id' => $tenantId]);

        // 3. Pending subscription with price/limit snapshot + unpaid invoice.
        $subtotal       = (int) $plan['price'] + (int) $office['monthly_price'];
        $subscriptionId = $this->insert('tenant_subscriptions', [
            'tenant_id'       => $tenantId,
            'plan_id'         => $plan['id'],
            'office_id'       => $office['id'],
            'status'          => 'pending',
            'price_snapshot'  => $subtotal,
            'limits_snapshot' => json_encode([
                'max_agents'            => (int) $plan['max_agents'],
                'max_operational_rooms' => (int) $plan['max_operational_rooms'],
                'monthly_token_quota'   => (int) $plan['monthly_token_quota'],
                'duration_days'         => (int) $plan['duration_days'],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $lineItems = [['description' => "Paket {$plan['name']} ({$plan['duration_days']} hari)", 'amount' => (int) $plan['price']]];
        if ((int) $office['monthly_price'] > 0) {
            $lineItems[] = ['description' => "Sewa kantor {$office['office_number']}", 'amount' => (int) $office['monthly_price']];
        }
        $invoiceId = $this->insert('invoices', [
            'tenant_id'       => $tenantId,
            'subscription_id' => $subscriptionId,
            'plan_id'         => $plan['id'],
            'invoice_number'  => InvoiceNumber::next(),
            'description'     => "Langganan {$plan['name']} — Suite {$office['office_number']}",
            'subtotal'        => $subtotal,
            'fee'             => 0, // payment channel fee is added when a channel is chosen (Phase 4)
            'total'           => $subtotal,
            'currency'        => 'IDR',
            'status'          => 'unpaid',
            'due_at'          => $reservedTill,
            'line_items'      => json_encode($lineItems),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        // 4. Rooms per plan (visible as locked/unlocked once the subscription is active).
        (new RoomProvisioner($db))->provision($tenantId, (int) $office['id'], $plan);

        $db->transComplete();

        (new NotificationService())->notify($tenantId, 'invoice.created', 'Kantor di-reserve, invoice dibuat',
            "Suite {$office['office_number']} di-reserve sampai {$reservedTill}. Bayar invoice sebesar Rp " . number_format($subtotal, 0, ',', '.') . ' untuk mengaktifkan langganan.',
            ['invoice_id' => $invoiceId]);
        Services::auditLogger()->record('tenant.register', [
            'tenant_id' => $tenantId, 'user_id' => $userId, 'entity_type' => 'tenant', 'entity_id' => $tenantId,
            'new' => ['plan' => $plan['code'], 'office' => $office['office_number'], 'invoice_id' => $invoiceId],
        ]);

        return ['tenant_id' => $tenantId, 'user_id' => $userId, 'invoice_id' => $invoiceId];
    }

    private function insert(string $table, array $row): int
    {
        $db = Database::connect();
        $db->table($table)->insert($row);

        return (int) $db->insertID();
    }

    private function uniqueSlug(string $name): string
    {
        $base = substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'tenant', 0, 90);
        $slug = $base;
        $db   = Database::connect();
        for ($i = 2; $db->table('tenants')->where('slug', $slug)->countAllResults() > 0; $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function uuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
