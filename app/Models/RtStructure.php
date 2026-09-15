<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RtStructure extends Model
{
    protected $fillable = [
        'rw_id',
        'rt_number',
        'name',
        'ketua_rt_user_id',
        'sekretaris_rt_user_id',
        'bendahara_rt_user_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function rwStructure(): BelongsTo
    {
        return $this->belongsTo(RwStructure::class, 'rw_id');
    }

    public function ketuaRt(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ketua_rt_user_id');
    }

    public function sekretarisRt(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sekretaris_rt_user_id');
    }

    public function bendaharaRt(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bendahara_rt_user_id');
    }

    public function wargaProfiles(): HasMany
    {
        return $this->hasMany(WargaProfile::class, 'rt_id');
    }

    public function letterRequests(): HasMany
    {
        return $this->hasMany(LetterRequest::class, 'rt_id');
    }

    public function letterSequences(): HasMany
    {
        return $this->hasMany(LetterSequence::class, 'rt_id');
    }
}
