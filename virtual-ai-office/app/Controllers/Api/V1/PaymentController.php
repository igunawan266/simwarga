<?php

namespace App\Controllers\Api\V1;

use App\Exceptions\DomainRuleException;
use App\Models\UserModel;
use App\Services\Payment\PaymentService;
use Config\Services;

/** /api/v1/office/invoices/{id}/... — tenant owner/admin pays its own invoices. */
class PaymentController extends BaseApiController
{
    public function __construct(private readonly PaymentService $payments = new PaymentService())
    {
    }

    public function channels(int $invoiceId)
    {
        return $this->run(fn () => $this->payments->channels($invoiceId));
    }

    public function pay(int $invoiceId)
    {
        $data = $this->input(['method']);
        if ($fail = $this->invalid($data, ['method' => 'required|alpha_dash|max_length[50]'])) {
            return $fail;
        }
        $user   = model(UserModel::class)->find(Services::currentUser()->id());
        $tenant = Services::tenantContext()->tenant();

        return $this->run(fn () => ['payment' => $this->payments->pay($invoiceId, strtoupper($data['method']), [
            'name'  => mb_substr($tenant['company_name'] . ' — ' . $user['name'], 0, 100),
            'email' => $tenant['contact_email'] ?: $user['email'],
            'phone' => $tenant['contact_phone'] ?? '',
        ])]);
    }

    public function status(int $invoiceId)
    {
        return $this->run(fn () => $this->payments->status($invoiceId));
    }

    private function run(callable $fn)
    {
        try {
            return $this->ok($fn());
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }
    }
}
