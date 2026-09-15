<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterSequence extends Model
{
    protected $fillable = [
        'rt_id',
        'rw_id',
        'last_sequence_number',
        'last_sequence_month',
        'last_sequence_year',
    ];

    public function rtStructure(): BelongsTo
    {
        return $this->belongsTo(RtStructure::class, 'rt_id');
    }

    public function rwStructure(): BelongsTo
    {
        return $this->belongsTo(RwStructure::class, 'rw_id');
    }
}
