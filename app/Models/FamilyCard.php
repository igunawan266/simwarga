<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FamilyCard extends Model
{
    protected $fillable = [
        'head_of_family_id',
        'no_kk',
        'alamat_jalan',
        'nomor_rumah',
        'rt_id',
        'rw_id',
        'tanggal_cetak',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'tanggal_cetak' => 'date',
    ];

    public function headOfFamily(): BelongsTo
    {
        return $this->belongsTo(WargaProfile::class, 'head_of_family_id');
    }

    public function rtStructure(): BelongsTo
    {
        return $this->belongsTo(RtStructure::class, 'rt_id');
    }

    public function rwStructure(): BelongsTo
    {
        return $this->belongsTo(RwStructure::class, 'rw_id');
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class);
    }
}
