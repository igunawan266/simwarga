<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Reference data required in every environment (production included):
 * roles, the building and its floors/offices, room types, default plans, AI model catalogue.
 * Prices and limits here are starting values only — Super Admin edits them later.
 */
class CoreSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $roles = [
            ['super_admin', 'Super Admin', 'platform'],
            ['user', 'Platform User', 'platform'],
            ['tenant_owner', 'Tenant Owner', 'tenant'],
            ['tenant_admin', 'Tenant Admin', 'tenant'],
            ['tenant_staff', 'Tenant Staff', 'tenant'],
            ['ai_agent', 'AI Agent', 'tenant'],
        ];
        foreach ($roles as [$slug, $name, $scope]) {
            $this->upsert('roles', ['slug' => $slug], ['name' => $name, 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now]);
        }

        $buildingId = $this->upsert('buildings', ['code' => 'VAOT'], [
            'name'         => 'Virtual AI Office Tower',
            'total_floors' => 12,
            'lobby_floor'  => 0,
            'theme'        => json_encode(['primary' => '#0b1f3a', 'accent' => '#22d3ee', 'silver' => '#c0c8d4']),
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        for ($n = 0; $n <= 12; $n++) {
            $isLobby = $n === 0;
            $floorId = $this->upsert('floors', ['building_id' => $buildingId, 'floor_number' => $n], [
                'name'               => $isLobby ? 'Main Lobby' : sprintf('Floor %02d', $n),
                'floor_type'         => $isLobby ? 'lobby' : 'office',
                'tenant_capacity'    => $isLobby ? 0 : 1,
                'base_monthly_price' => 0, // optional floor premium, set by Super Admin
                'status'             => $isLobby ? 'common' : 'available',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            if (! $isLobby) {
                $this->upsert('offices', ['office_number' => sprintf('%02d-01', $n)], [
                    'floor_id'      => $floorId,
                    'name'          => sprintf('Suite %02d-01', $n),
                    'area_sqm'      => 1200,
                    'monthly_price' => 0,
                    'status'        => 'available',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }

        // Floor plate is 40m x 28m centred at the origin. Positions are {x, z, w, d}.
        $roomTypes = [
            ['main_lobby', 'Main Lobby', 'common', 'Resepsionis virtual, AI Receptionist, identitas perusahaan dan daftar aktivitas kantor.', 'reception', [15, 7.5, 10, 11]],
            ['waiting_lounge', 'Waiting Lounge', 'common', 'Sofa, meja tamu, area tunggu dan layar informasi.', 'sofa', [5, 7.5, 10, 11]],
            ['pantry', 'Pantry & Coffee Corner', 'common', 'Meja pantry, mesin kopi, lemari penyimpanan dan area istirahat.', 'coffee', [-5, 7.5, 10, 11]],
            ['meeting_room', 'Meeting Room', 'common', 'Meja rapat, layar presentasi dan koordinasi antar-Agent AI.', 'meeting', [-15, 7.5, 10, 11]],
            ['ceo_office', 'CEO Office', 'operational', 'Dashboard pemilik tenant, statistik bisnis dan monitoring Agent AI.', 'crown', [16.67, -8, 6.66, 11]],
            ['marketing', 'Marketing Room', 'operational', 'AI Marketing, Social Media, Content Creator, SEO Specialist.', 'megaphone', [10, -8, 6.66, 11]],
            ['sales', 'Sales Room', 'operational', 'AI Sales, Lead Generator, Follow-up, CRM Assistant.', 'chart', [3.33, -8, 6.66, 11]],
            ['customer_service', 'Customer Service Room', 'operational', 'AI Customer Service, WhatsApp Support, Complaint Handler, Customer Assistant.', 'headset', [-3.33, -8, 6.66, 11]],
            ['finance', 'Finance Room', 'operational', 'AI Finance Assistant, Invoice Manager, Financial Reporting.', 'wallet', [-10, -8, 6.66, 11]],
            ['it_server', 'IT & Server Room', 'operational', 'AI IT Support, monitoring sistem, integrasi API, monitoring penggunaan AI.', 'server', [-16.67, -8, 6.66, 11]],
        ];
        foreach ($roomTypes as $i => [$slug, $name, $category, $desc, $icon, $pos]) {
            $this->upsert('room_types', ['slug' => $slug], [
                'name'        => $name,
                'category'    => $category,
                'description' => $desc,
                'icon'        => $icon,
                'layout'      => json_encode(['x' => $pos[0], 'z' => $pos[1], 'w' => $pos[2], 'd' => $pos[3]]),
                'sort_order'  => $i + 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $common      = ['main_lobby', 'waiting_lounge', 'pantry', 'meeting_room'];
        $plans = [
            ['STARTER', 'Starter', 'Satu kantor dengan fasilitas umum dan 2 Agent AI.', 299000, 1, 2, 0, 200000, $common, ['api_integration' => false, 'custom_office' => false], false],
            ['BUSINESS', 'Business', 'Fasilitas umum, 5 ruangan operasional dan 5 Agent AI.', 899000, 1, 5, 5, 1000000, array_merge($common, ['ceo_office', 'marketing', 'sales', 'customer_service', 'finance']), ['api_integration' => false, 'custom_office' => false], false],
            ['PROFESSIONAL', 'Professional', 'Fasilitas lengkap, seluruh ruangan operasional dan 15 Agent AI.', 2499000, 1, 15, 6, 5000000, null, ['api_integration' => true, 'custom_office' => false], false],
            ['ENTERPRISE', 'Enterprise', 'Multi-room, banyak Agent AI, kustomisasi kantor, integrasi API dan kapasitas khusus.', 0, 5, 100, 6, 0, null, ['api_integration' => true, 'custom_office' => true, 'dedicated_support' => true], true],
        ];
        foreach ($plans as $i => [$code, $name, $desc, $price, $offices, $agents, $opRooms, $tokens, $rooms, $features, $custom]) {
            $this->upsert('subscription_plans', ['code' => $code], [
                'name'                  => $name,
                'description'           => $desc,
                'price'                 => $price,
                'billing_period'        => 'monthly',
                'duration_days'         => 30,
                'max_offices'           => $offices,
                'max_agents'            => $agents,
                'max_operational_rooms' => $opRooms,
                'monthly_token_quota'   => $tokens,
                'allowed_room_types'    => $rooms === null ? null : json_encode($rooms),
                'features'              => json_encode($features),
                'is_custom'             => $custom ? 1 : 0,
                'is_active'             => 1,
                'sort_order'            => $i + 1,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        }

        // Pricing columns are left at 0: Super Admin fills them from the provider's current price list.
        $models = [
            ['anthropic', 'claude-sonnet-5-5', 'Claude Sonnet 5.5', 200000],
            ['anthropic', 'claude-haiku-5-5', 'Claude Haiku 5.5', 200000],
            ['anthropic', 'claude-opus-5-5', 'Claude Opus 5.5', 200000],
        ];
        foreach ($models as [$provider, $key, $display, $ctx]) {
            $this->upsert('ai_models', ['provider' => $provider, 'model_key' => $key], [
                'display_name'   => $display,
                'context_window' => $ctx,
                'supports_tools' => 1,
                'is_active'      => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }

        $settings = [
            ['general', 'platform_name', 'Virtual AI Office', 'string'],
            ['general', 'default_currency', 'IDR', 'string'],
            ['billing', 'invoice_due_hours', '24', 'int'],
            ['billing', 'grace_period_days', '3', 'int'],
            ['billing', 'renewal_days_before', '7', 'int'],
            ['billing', 'seller_name', 'Virtual AI Office', 'string'],
            ['billing', 'seller_address', '', 'string'],
            ['billing', 'seller_email', '', 'string'],
        ];
        foreach ($settings as [$group, $key, $value, $type]) {
            $this->upsert('system_settings', ['setting_group' => $group, 'setting_key' => $key], [
                'setting_value' => $value, 'value_type' => $type, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * Insert the row if no row matches $where, otherwise leave it untouched (so re-seeding
     * never overwrites values Super Admin has edited). Returns the row id.
     */
    protected function upsert(string $table, array $where, array $values): int
    {
        $existing = $this->db->table($table)->where($where)->get()->getRowArray();
        if ($existing) {
            return (int) $existing['id'];
        }
        $this->db->table($table)->insert($where + $values);

        return (int) $this->db->insertID();
    }
}
