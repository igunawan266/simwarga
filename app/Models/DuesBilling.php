<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DuesBilling extends Model
{
    protected $fillable = [
        'rt_id',
        'rw_id',
        'billing_month',
        'billing_year',
        'amount_per_house',
        'due_date',
        'status',
        'description',
        'created_by',
    ];

    protected $casts = [
        'amount_per_house' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function rtStructure(): BelongsTo
    {
        return $this->belongsTo(RtStructure::class, 'rt_id');
    }

    public function rwStructure(): BelongsTo
    {
        return $this->belongsTo(RwStructure::class, 'rw_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function duesPayments(): HasMany
    {
        return $this->hasMany(DuesPayment::class, 'billing_id');
    }
}
