<?php

namespace App\Models;

class InvoiceModel extends TenantScopedModel
{
    protected $table         = 'invoices';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['tenant_id', 'subscription_id', 'plan_id', 'invoice_number', 'description', 'subtotal', 'fee', 'total', 'currency', 'status', 'due_at', 'paid_at', 'line_items', 'kind'];
}
