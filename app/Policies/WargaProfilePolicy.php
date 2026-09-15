<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WargaProfile;

class WargaProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'rw', 'ketua_rt', 'sekretaris_rt']);
    }

    public function view(User $user, WargaProfile $profile): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->id === $profile->user_id) return true;

        // RT officers can view all profiles in their RT
        if (in_array($user->role, ['ketua_rt', 'sekretaris_rt']) && $user->rtStructure?->id === $profile->rt_id) {
            return true;
        }

        // RW can view all profiles in their RW
        if ($user->role === 'rw' && $user->rwStructure?->id === $profile->rw_id) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'rw', 'ketua_rt', 'sekretaris_rt']);
    }

    public function update(User $user, WargaProfile $profile): bool
    {
        if ($user->role === 'admin') return true;
        if ($user->id === $profile->user_id) return true;

        if (in_array($user->role, ['ketua_rt', 'sekretaris_rt']) && $user->rtStructure?->id === $profile->rt_id) {
            return true;
        }

        return false;
    }

    public function delete(User $user, WargaProfile $profile): bool
    {
        return $user->role === 'admin';
    }
}
