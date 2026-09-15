<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterSequenceAudit extends Model
{
    protected $table = 'letter_sequence_audit';
    public $timestamps = false;

    protected $fillable = [
        'letter_request_id',
        'rt_id',
        'rw_id',
        'generated_letter_number',
        'sequence_number',
        'generated_by',
        'generated_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function letterRequest(): BelongsTo
    {
        return $this->belongsTo(LetterRequest::class);
    }

    public function rtStructure(): BelongsTo
    {
        return $this->belongsTo(RtStructure::class, 'rt_id');
    }

    public function rwStructure(): BelongsTo
    {
        return $this->belongsTo(RwStructure::class, 'rw_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
