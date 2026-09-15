<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WargaProfile extends Model
{
    protected $fillable = [
        'user_id',
        'nik',
        'nama_lengkap',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'alamat_jalan',
        'nomor_rumah',
        'rt_id',
        'rw_id',
        'phone',
        'status_dalam_keluarga',
        'is_head_of_family',
        'no_kk',
        'status_perkawinan',
        'pekerjaan',
        'is_active',
    ];

    protected $casts = [
        'is_head_of_family' => 'boolean',
        'is_active' => 'boolean',
        'tanggal_lahir' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rtStructure(): BelongsTo
    {
        return $this->belongsTo(RtStructure::class, 'rt_id');
    }

    public function rwStructure(): BelongsTo
    {
        return $this->belongsTo(RwStructure::class, 'rw_id');
    }

    public function familyCard(): BelongsTo
    {
        return $this->belongsTo(FamilyCard::class, 'id', 'head_of_family_id');
    }

    public function letterRequests(): HasMany
    {
        return $this->hasMany(LetterRequest::class, 'warga_profile_id');
    }

    public function duesPayments(): HasMany
    {
        return $this->hasMany(DuesPayment::class, 'warga_profile_id');
    }
}
