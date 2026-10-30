<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
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
}
