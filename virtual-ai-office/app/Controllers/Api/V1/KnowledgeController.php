<?php

namespace App\Controllers\Api\V1;

use App\Exceptions\DomainRuleException;
use App\Services\AI\KnowledgeService;

/** /api/v1/office/knowledge — owners/admins (documents may be confidential). */
class KnowledgeController extends BaseApiController
{
    private KnowledgeService $knowledge;

    public function __construct()
    {
        $this->knowledge = new KnowledgeService();
    }

    public function index()
    {
        return $this->ok(['knowledge_bases' => $this->knowledge->list()]);
    }

    public function show(int $id)
    {
        return $this->run(fn () => $this->knowledge->detail($id));
    }

    /** multipart/form-data: name, description, and either `file` or `text`. */
    public function create()
    {
        $data = ['name' => (string) $this->request->getPost('name'), 'description' => (string) $this->request->getPost('description'), 'text' => (string) $this->request->getPost('text')];
        $file = $this->request->getFile('file');
        $hasFile = $file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE;

        $rules = ['name' => ($hasFile ? 'permit_empty' : 'required') . '|string|max_length[150]', 'description' => 'permit_empty|string|max_length[1000]'];
        if (! $hasFile) {
            $rules['text'] = 'required|string|min_length[20]';
        }
        if ($fail = $this->invalid($data, $rules)) {
            return $fail;
        }

        return $this->run(fn () => $hasFile
            ? $this->knowledge->createFromUpload($data['name'], $data['description'], $file)
            : $this->knowledge->createFromText($data['name'], $data['description'], $data['text']), 201);
    }

    public function delete(int $id)
    {
        return $this->run(function () use ($id) {
            $this->knowledge->delete($id);

            return ['deleted' => true];
        });
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
