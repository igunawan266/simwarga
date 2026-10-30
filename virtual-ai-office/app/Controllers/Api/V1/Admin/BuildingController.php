<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Exceptions\DomainRuleException;
use App\Services\Admin\BuildingAdminService;

/** /api/v1/admin/floors, /api/v1/admin/offices/* — Super Admin only. */
class BuildingController extends BaseApiController
{
    public function __construct(private readonly BuildingAdminService $building = new BuildingAdminService())
    {
    }

    public function floors()
    {
        return $this->ok(['floors' => $this->building->floors()]);
    }

    public function updateOffice(int $id)
    {
        $data = $this->input(['monthly_price', 'status', 'name', 'area_sqm']);
        $fail = $this->invalid($data, [
            'monthly_price' => 'permit_empty|is_natural|less_than_equal_to[100000000000]',
            'status'        => 'permit_empty|in_list[available,maintenance]',
            'name'          => 'permit_empty|string|max_length[150]',
            'area_sqm'      => 'permit_empty|is_natural|less_than_equal_to[100000]',
        ]);
        if ($fail) {
            return $fail;
        }

        try {
            return $this->ok(['office' => $this->building->updateOffice($id, $data)]);
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }
    }

    public function assign(int $id)
    {
        $data = $this->input(['tenant_id']);
        if ($fail = $this->invalid($data, ['tenant_id' => 'required|is_natural_no_zero'])) {
            return $fail;
        }

        try {
            return $this->ok(['office' => $this->building->assign($id, (int) $data['tenant_id'])]);
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }
    }

    public function release(int $id)
    {
        try {
            $this->building->release($id);

            return $this->ok(['released' => true]);
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }
    }
}
