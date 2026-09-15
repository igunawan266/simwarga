<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransaction extends Model
{
    protected $fillable = [
        'scope',
        'rt_id',
        'rw_id',
        'type',
        'category',
        'amount',
        'description',
        'transaction_date',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
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

    public function scopeByRt($query, $rtId)
    {
        return $query->where('scope', 'rt')->where('rt_id', $rtId);
    }

    public function scopeByRw($query, $rwId)
    {
        return $query->where('scope', 'rw')->where('rw_id', $rwId);
    }
}
