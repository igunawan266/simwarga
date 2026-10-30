<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Payment\Tripay\TripayException;
use App\Services\Payment\ChannelService;
use Config\Database;

/** /api/v1/admin/payment-channels — Super Admin. */
class PaymentChannelsController extends BaseApiController
{
    public function index()
    {
        $config = config('Tripay');

        return $this->ok([
            'gateway' => ['configured' => $config->isConfigured(), 'mode' => $config->mode, 'callback_url' => site_url('webhooks/tripay')],
            'channels' => Database::connect()->table('payment_channels')->select('code, name, group_name, fee_flat, fee_percent, is_active, synced_at')
                ->where('provider', 'tripay')->orderBy('group_name')->orderBy('name')->get()->getResultArray(),
        ]);
    }

    /** Any tenant's invoice as PDF (Super Admin). */
    public function invoicePdf(int $id)
    {
        $invoice = Database::connect()->table('invoices')->where('id', $id)->get()->getRowArray();
        if ($invoice === null) {
            return $this->failNotFound('Invoice tidak ditemukan.');
        }

        return $this->response->setContentType('application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $invoice['invoice_number'] . '.pdf"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody((new \App\Services\InvoicePdfService())->render($invoice));
    }

    public function sync()
    {
        if (! config('Tripay')->isConfigured()) {
            return $this->fail(['error' => 'Isi tripay.merchantCode, tripay.apiKey dan tripay.privateKey di .env terlebih dahulu.'], 503);
        }
        try {
            $count = (new ChannelService())->sync();
        } catch (TripayException $e) {
            return $this->fail(['error' => $e->getMessage()], 502);
        }

        return $this->ok(['synced' => $count]);
    }
}
