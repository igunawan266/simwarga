<?php

namespace App\Services;

use Config\Database;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Renders an invoice as PDF. Callers must have authorised access to the invoice already. */
class InvoicePdfService
{
    public function render(array $invoice): string
    {
        $db      = Database::connect();
        $tenant  = $db->table('tenants')->where('id', $invoice['tenant_id'])->get()->getRowArray();
        $payment = $invoice['status'] === 'paid' || $invoice['status'] === 'refunded'
            ? $db->table('payments p')->select('p.*, COALESCE(c.name, p.method_code) AS method')
                ->join('payment_channels c', 'c.id = p.channel_id', 'left')
                ->where('p.invoice_id', $invoice['id'])->whereIn('p.status', ['PAID', 'REFUND'])->orderBy('p.id', 'DESC')->get(1)->getRowArray()
            : null;

        $html = view('pdf/invoice', [
            'invoice' => $invoice,
            'tenant'  => $tenant,
            'payment' => $payment ?: null,
            'lines'   => json_decode($invoice['line_items'] ?? '[]', true) ?: [],
            'seller'  => [
                'name'    => SettingsService::get('billing', 'seller_name') ?: 'Virtual AI Office',
                'address' => (string) SettingsService::get('billing', 'seller_address', ''),
                'email'   => (string) SettingsService::get('billing', 'seller_email', ''),
            ],
        ], ['debug' => false]);

        $options = new Options();
        $options->setIsRemoteEnabled(false); // never fetch external resources while rendering
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }
}
