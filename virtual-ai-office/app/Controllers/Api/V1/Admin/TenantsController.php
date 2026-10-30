<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Exceptions\DomainRuleException;
use App\Services\Admin\TenantAdminService;

/** /api/v1/admin/tenants — Super Admin only (routed behind auth + role:super_admin). */
class TenantsController extends BaseApiController
{
    private const RULES = [
        'company_name'     => 'permit_empty|string|min_length[2]|max_length[150]',
        'industry'         => 'permit_empty|string|max_length[100]',
        'business_profile' => 'permit_empty|string|max_length[5000]',
        'contact_email'    => 'permit_empty|valid_email|max_length[191]',
        'contact_phone'    => 'permit_empty|regex_match[/^[0-9+\-\s]{6,30}$/]',
        'brand_color'      => 'permit_empty|regex_match[/^#[0-9a-fA-F]{6}$/]',
        'status'           => 'permit_empty|in_list[pending,active,suspended,expired]',
        'is_listed'        => 'permit_empty|in_list[0,1]',
        'logo_url'         => 'permit_empty|valid_url_strict[https]|max_length[255]',
    ];

    public function __construct(private readonly TenantAdminService $tenants = new TenantAdminService())
    {
    }

    public function index()
    {
        return $this->ok(['tenants' => $this->tenants->list()]);
    }

    public function show(int $id)
    {
        try {
            return $this->ok($this->tenants->show($id));
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }
    }

    public function create()
    {
        $data  = $this->input(TenantAdminService::EDITABLE);
        $rules = ['company_name' => 'required|' . substr(self::RULES['company_name'], strlen('permit_empty|'))] + self::RULES;
        if ($fail = $this->invalid($data, $rules)) {
            return $fail;
        }

        return $this->respondCreated(['data' => $this->tenants->create($data), 'csrf' => csrf_hash()]);
    }

    public function update(int $id)
    {
        $data = $this->input(TenantAdminService::EDITABLE);
        if ($fail = $this->invalid($data, self::RULES)) {
            return $fail;
        }
        try {
            return $this->ok($this->tenants->update($id, $data));
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }
    }
}
