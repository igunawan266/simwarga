<?php

namespace App\Controllers\Api\V1;

use App\Services\BuildingService;

/** GET /api/v1/building — public directory for the 3D exterior. */
class BuildingController extends BaseApiController
{
    public function index()
    {
        $data = (new BuildingService())->directory();

        return $data === null ? $this->failNotFound('Building not configured.') : $this->ok($data);
    }
}
