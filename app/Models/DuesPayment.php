<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuesPayment extends Model
{
    protected $fillable = [
        'billing_id',
        'warga_profile_id',
        'paid_amount',
        'payment_date',
        'payment_method',
        'status',
        'notes',
    ];

    protected $casts = [
        'paid_amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function duesBilling(): BelongsTo
    {
        return $this->belongsTo(DuesBilling::class, 'billing_id');
    }

    public function wargaProfile(): BelongsTo
    {
        return $this->belongsTo(WargaProfile::class);
    }
}
