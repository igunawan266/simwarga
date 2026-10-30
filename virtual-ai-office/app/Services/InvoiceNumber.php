<?php

namespace App\Services;

use Config\Database;

/** INV-YYYYMMDD-XXXXXX (random hex, retried until unique). */
final class InvoiceNumber
{
    public static function next(): string
    {
        $db = Database::connect();
        do {
            $number = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        } while ($db->table('invoices')->where('invoice_number', $number)->countAllResults() > 0);

        return $number;
    }
}
