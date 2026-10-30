<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Exceptions\DomainRuleException;
use CodeIgniter\API\ResponseTrait;

abstract class BaseApiController extends BaseController
{
    use ResponseTrait;

    protected $format = 'json';

    /** Every JSON response carries a fresh CSRF token for the next state-changing call. */
    protected function ok(array $data)
    {
        return $this->respond(['data' => $data, 'csrf' => csrf_hash()]);
    }

    /** Form fields of a POST/PATCH request, limited to $fields. */
    protected function input(array $fields): array
    {
        $all = $this->request->getMethod() === 'POST' ? $this->request->getPost() : $this->request->getRawInput();

        return array_intersect_key((array) $all, array_flip($fields));
    }

    /** Validate $data against rules; returns a failure response or null. */
    protected function invalid(array $data, array $rules)
    {
        return $this->validateData($data, $rules) ? null : $this->failValidationErrors($this->validator->getErrors());
    }

    protected function domainError(DomainRuleException $e)
    {
        return $this->fail(['error' => $e->getMessage()], $e->status());
    }
}
