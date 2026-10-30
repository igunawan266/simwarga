<?php

namespace App\Controllers\Webhooks;

use App\Controllers\BaseController;
use App\Services\Payment\CallbackService;

/**
 * POST /webhooks/tripay — set this as the Callback URL in the Tripay merchant dashboard.
 * No session/CSRF: authenticity comes only from the HMAC signature over the raw body.
 */
class TripayController extends BaseController
{
    public function handle()
    {
        [$status, $body] = (new CallbackService())->handle(
            (string) $this->request->getBody(),
            $this->request->getHeaderLine('X-Callback-Signature'),
            $this->request->getHeaderLine('X-Callback-Event'),
            $this->request->getIPAddress(),
        );

        return $this->response->setStatusCode($status)->setJSON($body);
    }
}
