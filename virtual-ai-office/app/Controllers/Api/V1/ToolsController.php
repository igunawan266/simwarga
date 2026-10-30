<?php

namespace App\Controllers\Api\V1;

use App\Exceptions\DomainRuleException;
use App\Services\AI\ApprovalService;
use App\Services\AI\ToolService;
use Config\Services;

/** /api/v1/office/tools and /approvals — owners/admins. */
class ToolsController extends BaseApiController
{
    private const RULES = [
        'name'                => 'permit_empty|string|min_length[2]|max_length[100]',
        'description'         => 'permit_empty|string|min_length[10]|max_length[1000]',
        'method'              => 'permit_empty|in_list[GET,POST,PUT,PATCH,DELETE]',
        'url'                 => 'permit_empty|string|max_length[500]',
        'params'              => 'permit_empty|valid_json',
        'headers'             => 'permit_empty|valid_json',
        'secret_header_name'  => 'permit_empty|regex_match[/^[A-Za-z0-9-]{1,64}$/]',
        'secret_header_value' => 'permit_empty|string|max_length[2000]',
        'approval_mode'       => 'permit_empty|in_list[approval,auto]',
        'is_active'           => 'permit_empty|in_list[0,1]',
    ];
    private const FIELDS = ['name', 'description', 'method', 'url', 'params', 'headers', 'secret_header_name', 'secret_header_value', 'approval_mode', 'is_active'];

    public function index()
    {
        $tools = new ToolService();

        return $this->ok(['tools' => $tools->list(), 'plan_allows' => $tools->planAllows()]);
    }

    public function create()
    {
        $data = $this->input(self::FIELDS);
        $fail = $this->invalid($data, ['name' => 'required|string|min_length[2]|max_length[100]', 'description' => 'required|string|min_length[10]|max_length[1000]',
            'method' => 'required|in_list[GET,POST,PUT,PATCH,DELETE]', 'url' => 'required|string|max_length[500]'] + self::RULES);

        return $fail ?? $this->run(fn () => ['tool' => (new ToolService())->create($data)], 201);
    }

    public function update(int $id)
    {
        $data = $this->input(self::FIELDS);

        return $this->invalid($data, self::RULES) ?? $this->run(fn () => ['tool' => (new ToolService())->update($id, $data)]);
    }

    public function delete(int $id)
    {
        return $this->run(function () use ($id) {
            (new ToolService())->delete($id);

            return ['deleted' => true];
        });
    }

    public function test(int $id)
    {
        $input = json_decode((string) $this->request->getPost('input'), true);

        return $this->run(fn () => ['result' => (new ToolService())->test($id, is_array($input) ? $input : [])]);
    }

    public function approvals()
    {
        return $this->ok(['approvals' => (new ApprovalService())->pending()]);
    }

    public function approve(int $id)
    {
        return $this->decide($id, true);
    }

    public function reject(int $id)
    {
        return $this->decide($id, false);
    }

    private function decide(int $id, bool $approve)
    {
        $note = (string) ($this->request->getPost('note') ?? '');

        return $this->run(fn () => (new ApprovalService())->decide($id, $approve, $note ?: null, (int) Services::currentUser()->id()));
    }

    private function run(callable $fn, int $status = 200)
    {
        try {
            $data = $fn();
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }

        return $status === 201 ? $this->respondCreated(['data' => $data, 'csrf' => csrf_hash()]) : $this->ok($data);
    }
}
