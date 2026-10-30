<?php

namespace App\Controllers\Api\V1;

use App\Services\OfficeService;

/** Tenant office endpoints. Routed behind auth + tenant filters. */
class OfficeController extends BaseApiController
{
    public function __construct(private readonly OfficeService $office = new OfficeService())
    {
    }

    public function show()
    {
        $data = $this->office->overview();
        if ($data === null) {
            return $this->failNotFound('Tenant belum memiliki kantor di gedung ini.');
        }
        if ($data['subscription'] === null) {
            return $this->fail(['error' => 'Langganan tidak aktif. Silakan perpanjang paket untuk masuk ke kantor.', 'data' => $data], 402);
        }

        return $this->ok($data);
    }

    public function room(int $id)
    {
        $data = $this->office->room($id);

        return $data === null ? $this->failNotFound('Ruangan tidak ditemukan.') : $this->ok($data);
    }
}
