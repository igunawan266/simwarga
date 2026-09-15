<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'email',
        'password',
        'phone',
        'is_active',
        'email_verified_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function role()
    {
        return $this->roles()->first()?->name;
    }

    public function wargaProfile()
    {
        return $this->hasOne(WargaProfile::class);
    }

    public function rtStructure()
    {
        return $this->hasOne(RtStructure::class, 'ketua_rt_user_id')
            ->orWhere('sekretaris_rt_user_id', $this->id)
            ->orWhere('bendahara_rt_user_id', $this->id);
    }

    public function rwStructure()
    {
        return $this->hasOne(RwStructure::class, 'ketua_rw_user_id');
    }

    public function assignRole(string $roleName)
    {
        $this->roles()->detach();
        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $this->roles()->attach($role);
        }
    }
}
