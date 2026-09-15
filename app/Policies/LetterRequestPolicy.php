<?php

namespace App\Policies;

use App\Models\User;
use App\Models\LetterRequest;

class LetterRequestPolicy
{
    public function create(User $user): bool
    {
        return $user->role === 'warga';
    }

    public function viewForRT(User $user, LetterRequest $letter): bool
    {
        if ($user->role === 'admin') return true;

        // RT officers can view letters for their RT
        if (in_array($user->role, ['ketua_rt', 'sekretaris_rt']) && $user->rtStructure?->id === $letter->rt_id) {
            return true;
        }

        return false;
    }

    public function approveForRT(User $user, LetterRequest $letter): bool
    {
        if ($user->role === 'admin') return true;

        // Only Ketua RT and Sekretaris RT can approve
        if (in_array($user->role, ['ketua_rt', 'sekretaris_rt']) && $user->rtStructure?->id === $letter->rt_id) {
            return true;
        }

        return false;
    }

    public function viewForRW(User $user, LetterRequest $letter): bool
    {
        if ($user->role === 'admin') return true;

        // RW can view letters in their RW
        if ($user->role === 'rw' && $user->rwStructure?->id === $letter->rw_id) {
            return true;
        }

        return false;
    }

    public function approveForRW(User $user, LetterRequest $letter): bool
    {
        if ($user->role === 'admin') return true;

        // Only Ketua RW can approve at RW level
        if ($user->role === 'rw' && $user->rwStructure?->id === $letter->rw_id) {
            return true;
        }

        return false;
    }

    public function reject(User $user, LetterRequest $letter): bool
    {
        return $this->approveForRT($user, $letter) || $this->approveForRW($user, $letter);
    }
}
