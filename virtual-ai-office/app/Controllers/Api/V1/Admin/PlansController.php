<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Exceptions\DomainRuleException;
use App\Services\Admin\PlanAdminService;

/** /api/v1/admin/plans — Super Admin only. All prices and limits are editable here. */
class PlansController extends BaseApiController
{
    private const RULES = [
        'name'                  => 'permit_empty|string|min_length[2]|max_length[100]',
        'description'           => 'permit_empty|string|max_length[2000]',
        'price'                 => 'permit_empty|is_natural|less_than_equal_to[100000000000]',
        'billing_period'        => 'permit_empty|in_list[monthly,quarterly,yearly]',
        'duration_days'         => 'permit_empty|is_natural_no_zero|less_than_equal_to[3660]',
        'max_offices'           => 'permit_empty|is_natural_no_zero|less_than_equal_to[100]',
        'max_agents'            => 'permit_empty|is_natural|less_than_equal_to[10000]',
        'max_operational_rooms' => 'permit_empty|is_natural|less_than_equal_to[100]',
        'monthly_token_quota'   => 'permit_empty|is_natural',
        'allowed_room_types'    => 'permit_empty|valid_json',
        'features'              => 'permit_empty|valid_json',
        'is_custom'             => 'permit_empty|in_list[0,1]',
        'is_active'             => 'permit_empty|in_list[0,1]',
        'sort_order'            => 'permit_empty|is_natural',
    ];

    public function __construct(private readonly PlanAdminService $plans = new PlanAdminService())
    {
    }

    public function index()
    {
        return $this->ok(['plans' => $this->plans->list()]);
    }

    public function create()
    {
        $data = $this->input([...PlanAdminService::EDITABLE, 'code']);
        $fail = $this->invalid($data, ['code' => 'required|alpha_dash|max_length[30]', 'name' => 'required|string|min_length[2]|max_length[100]'] + self::RULES);
        if ($fail) {
            return $fail;
        }
        try {
            return $this->respondCreated(['data' => $this->plans->create($data), 'csrf' => csrf_hash()]);
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }
    }

    public function update(int $id)
    {
        $data = $this->input(PlanAdminService::EDITABLE);
        if ($fail = $this->invalid($data, self::RULES)) {
            return $fail;
        }
        try {
            return $this->ok($this->plans->update($id, $data));
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }
    }
}
