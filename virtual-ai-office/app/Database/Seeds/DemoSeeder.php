<?php

namespace App\Database\Seeds;

use App\Services\RoomProvisioner;
use CodeIgniter\Database\Seeder;

/**
 * DEVELOPMENT / DEMO DATA ONLY — never run in production (DatabaseSeeder skips it).
 *
 * Creates a demo super admin, one fully furnished demo tenant on floor 7 and a few
 * listed demo tenants so the building has lit floors. Subscriptions are created as
 * "trial" and NO invoices/payments are seeded: payment records only ever come from Tripay.
 *
 * Demo logins (change or delete before going live):
 *   admin@demo.test  / DemoOffice#2026   (Super Admin)
 *   owner@demo.test  / DemoOffice#2026   (Tenant Owner, Nusantara Digital — floor 7)
 *   staff@demo.test  / DemoOffice#2026   (Tenant Staff, Nusantara Digital — floor 7)
 *   owner2@demo.test / DemoOffice#2026   (Tenant Owner, Arunika Logistics — floor 3)
 */
class DemoSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'DemoOffice#2026';

    private string $now;

    public function run(): void
    {
        $this->now = date('Y-m-d H:i:s');
        $roles     = array_column($this->db->table('roles')->get()->getResultArray(), 'id', 'slug');
        $hash      = password_hash(self::DEMO_PASSWORD, PASSWORD_DEFAULT);

        $this->user('admin@demo.test', 'Demo Super Admin', $roles['super_admin'], $hash);

        $tenants = [
            // slug, company, color, industry, floor, plan
            ['nusantara-digital', 'Nusantara Digital Group', '#22d3ee', 'Digital Agency', 7, 'PROFESSIONAL'],
            ['arunika-logistics', 'Arunika Logistics', '#38bdf8', 'Logistik', 3, 'BUSINESS'],
            ['sagara-retail', 'Sagara Retail', '#a78bfa', 'Retail', 5, 'STARTER'],
            ['bumi-fintech', 'Bumi Fintech', '#34d399', 'Fintech', 9, 'ENTERPRISE'],
            ['kirana-health', 'Kirana Health', '#f472b6', 'Kesehatan', 11, 'BUSINESS'],
        ];

        foreach ($tenants as [$slug, $company, $color, $industry, $floorNo, $planCode]) {
            if ($this->db->table('tenants')->where('slug', $slug)->countAllResults() > 0) {
                continue; // already seeded
            }
            $tenantId = $this->insert('tenants', [
                'uuid'             => $this->uuid(),
                'slug'             => $slug,
                'company_name'     => $company,
                'brand_color'      => $color,
                'industry'         => $industry,
                'business_profile' => "[DEMO] {$company} adalah tenant contoh untuk prototipe Virtual AI Office.",
                'contact_email'    => 'hello@' . $slug . '.demo.test',
                'status'           => 'active',
                'is_listed'        => 1,
                'created_at'       => $this->now,
                'updated_at'       => $this->now,
            ]);

            $floor  = $this->db->table('floors')->where('floor_number', $floorNo)->get()->getRowArray();
            $office = $this->db->table('offices')->where('floor_id', $floor['id'])->get()->getRowArray();
            $this->db->table('offices')->where('id', $office['id'])->update(['tenant_id' => $tenantId, 'status' => 'occupied', 'updated_at' => $this->now]);
            $this->db->table('floors')->where('id', $floor['id'])->update(['status' => 'occupied', 'updated_at' => $this->now]);

            $plan = $this->db->table('subscription_plans')->where('code', $planCode)->get()->getRowArray();
            $this->insert('tenant_subscriptions', [
                'tenant_id'       => $tenantId,
                'plan_id'         => $plan['id'],
                'office_id'       => $office['id'],
                'status'          => 'trial',
                'starts_at'       => $this->now,
                'ends_at'         => date('Y-m-d H:i:s', strtotime('+14 days')),
                'price_snapshot'  => 0,
                'limits_snapshot' => json_encode(['max_agents' => $plan['max_agents'], 'max_operational_rooms' => $plan['max_operational_rooms']]),
                'created_at'      => $this->now,
                'updated_at'      => $this->now,
            ]);

            $rooms = (new RoomProvisioner($this->db))->provision($tenantId, (int) $office['id'], $plan);

            if ($slug === 'nusantara-digital') {
                $owner = $this->user('owner@demo.test', 'Rani Prasetyo (Demo)', $roles['user'], $hash);
                $staff = $this->user('staff@demo.test', 'Bima Santoso (Demo)', $roles['user'], $hash);
                $this->member($tenantId, $owner, $roles['tenant_owner']);
                $this->member($tenantId, $staff, $roles['tenant_staff']);
            } elseif ($slug === 'arunika-logistics') {
                $owner = $this->user('owner2@demo.test', 'Dewi Lestari (Demo)', $roles['user'], $hash);
                $this->member($tenantId, $owner, $roles['tenant_owner']);
            }

            // Demo data respects the plan's agent limit like real tenants do.
            $this->createAgents($tenantId, $rooms, (int) $plan['max_agents']);
        }
    }

    private function createAgents(int $tenantId, array $rooms, int $limit): void
    {
        $model = $this->db->table('ai_models')->where('model_key', config('Ai')->defaultModel)->get()->getRowArray();

        // name, title, department, room slug, status, description
        $agents = [
            ['Aira', 'AI Receptionist', 'Front Office', 'main_lobby', 'online', 'Menyambut tamu, menjawab pertanyaan umum dan mengarahkan ke departemen.'],
            ['Atlas', 'AI Manager', 'Management', 'ceo_office', 'working', 'Menerima instruksi pemilik lalu membagi pekerjaan ke agent lain.'],
            ['Byte', 'AI IT Support', 'IT', 'it_server', 'online', 'Memantau sistem, integrasi API dan penggunaan AI.'],
            ['Maya', 'AI Marketing', 'Marketing', 'marketing', 'working', 'Menyusun strategi kampanye dan kalender konten.'],
            ['Sora', 'AI Social Media', 'Marketing', 'marketing', 'idle', 'Menjadwalkan dan membalas konten media sosial.'],
            ['Kenzo', 'AI Content Creator', 'Marketing', 'marketing', 'working', 'Menulis artikel, caption dan naskah video.'],
            ['Lumi', 'AI SEO Specialist', 'Marketing', 'marketing', 'waiting', 'Riset kata kunci dan audit SEO.'],
            ['Raka', 'AI Sales', 'Sales', 'sales', 'working', 'Menyusun penawaran dan menutup penjualan.'],
            ['Nara', 'AI Lead Generator', 'Sales', 'sales', 'online', 'Mencari dan mengkualifikasi prospek.'],
            ['Dion', 'AI Follow-up', 'Sales', 'sales', 'idle', 'Menindaklanjuti prospek secara terjadwal.'],
            ['Ciko', 'AI CRM Assistant', 'Sales', 'sales', 'online', 'Merapikan data pelanggan di CRM.'],
            ['Sari', 'AI Customer Service', 'Customer Service', 'customer_service', 'working', 'Menjawab pertanyaan pelanggan.'],
            ['Wira', 'AI WhatsApp Support', 'Customer Service', 'customer_service', 'online', 'Melayani pelanggan via WhatsApp (integrasi Phase 3).'],
            ['Tara', 'AI Complaint Handler', 'Customer Service', 'customer_service', 'error', 'Menangani keluhan dan eskalasi.'],
            ['Fina', 'AI Finance Assistant', 'Finance', 'finance', 'idle', 'Membantu pencatatan keuangan.'],
            ['Ivo', 'AI Invoice Manager', 'Finance', 'finance', 'working', 'Membuat dan memantau invoice pelanggan.'],
        ];
        $agents = array_slice($agents, 0, $limit);

        $managerId = null;
        foreach ($agents as [$name, $title, $dept, $room, $status, $desc]) {
            $id = $this->insert('ai_agents', [
                'tenant_id'        => $tenantId,
                'room_id'          => $rooms[$room] ?? null,
                'ai_model_id'      => $model['id'] ?? null,
                'manager_agent_id' => $title === 'AI Manager' ? null : $managerId,
                'name'             => $name,
                'job_title'        => $title,
                'department'       => $dept,
                'job_description'  => $desc,
                'system_prompt'    => "Kamu adalah {$name}, {$title}. {$desc} Jawab dalam Bahasa Indonesia yang profesional.",
                'api_config'       => json_encode(['effort' => 'medium']),
                'status'           => $status,
                'is_active'        => $status === 'offline' ? 0 : 1,
                'token_limit_monthly' => 100000,
                'created_at'       => $this->now,
                'updated_at'       => $this->now,
            ]);
            if ($title === 'AI Manager') {
                $managerId = $id;
            }
        }
    }

    private function user(string $email, string $name, $roleId, string $hash): int
    {
        $row = $this->db->table('users')->where('email', $email)->get()->getRowArray();
        if ($row) {
            return (int) $row['id'];
        }

        return $this->insert('users', [
            'role_id' => $roleId, 'name' => $name, 'email' => $email, 'password_hash' => $hash,
            'status' => 'active', 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
    }

    private function member(int $tenantId, int $userId, $roleId): void
    {
        $this->insert('tenant_members', [
            'tenant_id' => $tenantId, 'user_id' => $userId, 'role_id' => $roleId, 'status' => 'active',
            'joined_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
    }

    private function insert(string $table, array $row): int
    {
        $this->db->table($table)->insert($row);

        return (int) $this->db->insertID();
    }

    private function uuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
