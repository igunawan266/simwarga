<?php

namespace App\Controllers\Api\V1;

use App\Exceptions\DomainRuleException;
use App\Models\UserModel;
use App\Services\AuthService;
use App\Services\RegistrationService;
use Config\Database;

/** Public: plan catalogue and self-service tenant registration. */
class RegistrationController extends BaseApiController
{
    public function plans()
    {
        $rows = Database::connect()->table('subscription_plans')
            ->select('code, name, description, price, billing_period, duration_days, max_agents, max_operational_rooms, max_offices, monthly_token_quota, features, is_custom')
            ->where('is_active', 1)->where('deleted_at', null)->orderBy('sort_order')
            ->get()->getResultArray();

        return $this->ok(['plans' => array_map(static fn (array $p) => [
            'code'                  => $p['code'],
            'name'                  => $p['name'],
            'description'           => $p['description'],
            'price'                 => (int) $p['price'],
            'duration_days'         => (int) $p['duration_days'],
            'max_agents'            => (int) $p['max_agents'],
            'max_operational_rooms' => (int) $p['max_operational_rooms'],
            'features'              => json_decode($p['features'] ?? '[]', true),
            'is_custom'             => (bool) $p['is_custom'],
        ], $rows)]);
    }

    public function register()
    {
        $userId = (int) session('user_id');
        $user   = $userId ? model(UserModel::class)->findWithRole($userId) : null;
        $guest  = $user === null || $user['status'] !== 'active';

        $data  = $this->input(['company_name', 'industry', 'brand_color', 'plan_code', 'floor_number', 'owner_name', 'email', 'password', 'password_confirm']);
        $rules = [
            'company_name' => 'required|string|min_length[2]|max_length[150]',
            'industry'     => 'permit_empty|string|max_length[100]',
            'brand_color'  => 'permit_empty|regex_match[/^#[0-9a-fA-F]{6}$/]',
            'plan_code'    => 'required|alpha_dash|max_length[30]',
            'floor_number' => 'required|is_natural_no_zero|less_than_equal_to[500]',
        ];
        if ($guest) {
            $rules += [
                'owner_name'       => 'required|string|min_length[2]|max_length[150]',
                'email'            => 'required|valid_email|max_length[191]',
                'password'         => 'required|min_length[10]|max_length[200]',
                'password_confirm' => 'required|matches[password]',
            ];
        }
        if (! $this->validateData($data, $rules, [
            'company_name'     => ['required' => 'Nama perusahaan wajib diisi.'],
            'owner_name'       => ['required' => 'Nama Anda wajib diisi.'],
            'email'            => ['required' => 'Email wajib diisi.', 'valid_email' => 'Format email tidak valid.'],
            'password'         => ['required' => 'Password wajib diisi.', 'min_length' => 'Password minimal 10 karakter.'],
            'password_confirm' => ['matches' => 'Konfirmasi password tidak sama.'],
            'brand_color'      => ['regex_match' => 'Warna brand harus format #RRGGBB.'],
        ])) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        try {
            $result = (new RegistrationService())->register($data, $guest ? null : (int) $user['id']);
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }

        (new AuthService())->startSession($result['user_id'], $result['tenant_id']);

        return $this->respondCreated(['data' => ['registered' => true], 'csrf' => csrf_hash()]);
    }
}
