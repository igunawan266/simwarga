<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyMember extends Model
{
    protected $fillable = [
        'family_card_id',
        'warga_profile_id',
        'nik',
        'nama_lengkap',
        'hubungan_keluarga',
        'jenis_kelamin',
        'tanggal_lahir',
        'tempat_lahir',
        'status_perkawinan',
        'pekerjaan',
        'pendidikan',
        'status_dalam_keluarga',
    ];

    protected $casts = [
        'tanggal_lahir' => 'date',
    ];

    public function familyCard(): BelongsTo
    {
        return $this->belongsTo(FamilyCard::class);
    }

    public function wargaProfile(): BelongsTo
    {
        return $this->belongsTo(WargaProfile::class);
    }
}
