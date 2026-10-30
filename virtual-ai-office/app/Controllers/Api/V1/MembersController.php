<?php

namespace App\Controllers\Api\V1;

use App\Exceptions\DomainRuleException;
use App\Services\MemberService;
use Config\Services;

/**
 * /api/v1/office/members — the tenant's own team. The tenant id and the actor's role
 * come from the verified TenantContext; MemberService enforces who may change what.
 */
class MembersController extends BaseApiController
{
    public function __construct(private readonly MemberService $members = new MemberService())
    {
    }

    public function index()
    {
        $ctx = Services::tenantContext();

        return $this->ok([
            'members'     => $this->members->list($ctx->id()),
            'my_role'     => $ctx->role(),
            'my_user_id'  => Services::currentUser()->id(),
        ]);
    }

    public function invite()
    {
        $data = $this->input(['email', 'role']);
        if ($fail = $this->invalid($data, ['email' => 'required|valid_email|max_length[191]', 'role' => 'required|in_list[tenant_owner,tenant_admin,tenant_staff]'])) {
            return $fail;
        }

        return $this->run(fn () => $this->members->invite($this->tenantId(), $data['email'], $data['role'], $this->role(), $this->userId()), 201);
    }

    public function update(int $id)
    {
        $data = $this->input(['role', 'status']);
        if ($fail = $this->invalid($data, ['role' => 'permit_empty|in_list[tenant_owner,tenant_admin,tenant_staff]', 'status' => 'permit_empty|in_list[active,disabled]'])) {
            return $fail;
        }

        return $this->run(fn () => ['member' => $this->members->update($this->tenantId(), $id, array_filter($data), $this->role(), $this->userId())]);
    }

    public function remove(int $id)
    {
        return $this->run(function () use ($id) {
            $this->members->remove($this->tenantId(), $id, $this->role(), $this->userId());

            return ['removed' => true];
        });
    }

    public function reinvite(int $id)
    {
        return $this->run(fn () => $this->members->reinvite($this->tenantId(), $id, $this->role(), $this->userId()));
    }

    protected function tenantId(): int
    {
        return Services::tenantContext()->id();
    }

    protected function role(): string
    {
        return (string) Services::tenantContext()->role();
    }

    protected function userId(): int
    {
        return (int) Services::currentUser()->id();
    }

    protected function run(callable $fn, int $status = 200)
    {
        try {
            $data = $fn();
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }

        return $status === 201 ? $this->respondCreated(['data' => $data, 'csrf' => csrf_hash()]) : $this->ok($data);
    }
}
