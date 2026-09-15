<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RwStructure extends Model
{
    protected $fillable = [
        'rw_number',
        'name',
        'kelurahan',
        'kecamatan',
        'kota',
        'provinsi',
        'ketua_rw_user_id',
        'total_rt_units',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function ketuaRw(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ketua_rw_user_id');
    }

    public function rtStructures(): HasMany
    {
        return $this->hasMany(RtStructure::class, 'rw_id');
    }

    public function wargaProfiles(): HasMany
    {
        return $this->hasMany(WargaProfile::class, 'rw_id');
    }

    public function letterRequests(): HasMany
    {
        return $this->hasMany(LetterRequest::class, 'rw_id');
    }
}
