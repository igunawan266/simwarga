<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterRequest extends Model
{
    protected $fillable = [
        'warga_profile_id',
        'rt_id',
        'rw_id',
        'letter_type',
        'purpose',
        'status',
        'letter_number',
        'request_date',
        'rejection_reason',
        'rejected_at',
        'rejected_by',
        'approved_by_rt_at',
        'approved_by_rt_user_id',
        'rt_qr_token',
        'rt_signature_hash',
        'approved_by_rw_at',
        'approved_by_rw_user_id',
        'rw_qr_token',
        'rw_signature_hash',
        'completed_at',
        'pdf_path',
    ];

    protected $casts = [
        'request_date' => 'date',
        'rejected_at' => 'datetime',
        'approved_by_rt_at' => 'datetime',
        'approved_by_rw_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function wargaProfile(): BelongsTo
    {
        return $this->belongsTo(WargaProfile::class);
    }

    public function rtStructure(): BelongsTo
    {
        return $this->belongsTo(RtStructure::class, 'rt_id');
    }

    public function rwStructure(): BelongsTo
    {
        return $this->belongsTo(RwStructure::class, 'rw_id');
    }

    public function approvedByRtUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_rt_user_id');
    }

    public function approvedByRwUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_rw_user_id');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
