<?php

namespace App\Policies;

use App\Models\User;
use App\Models\FinancialTransaction;

class FinancialTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'rw', 'ketua_rt', 'bendahara_rt']);
    }

    public function view(User $user, FinancialTransaction $transaction): bool
    {
        if ($user->role === 'admin') return true;

        // Bendahara RT can only view their own RT's transactions
        if ($user->role === 'bendahara_rt' && $transaction->scope === 'rt' && $user->rtStructure?->id === $transaction->rt_id) {
            return true;
        }

        // Ketua RT can view their own RT's transactions
        if ($user->role === 'ketua_rt' && $transaction->scope === 'rt' && $user->rtStructure?->id === $transaction->rt_id) {
            return true;
        }

        // RW can view all transactions in their RW
        if ($user->role === 'rw' && $user->rwStructure?->id === $transaction->rw_id) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'bendahara_rt', 'rw']);
    }

    public function update(User $user, FinancialTransaction $transaction): bool
    {
        if ($user->role === 'admin') return true;

        if ($transaction->scope === 'rt') {
            return $user->role === 'bendahara_rt' && $user->rtStructure?->id === $transaction->rt_id;
        }

        if ($transaction->scope === 'rw') {
            return $user->role === 'rw' && $user->rwStructure?->id === $transaction->rw_id;
        }

        return false;
    }

    public function delete(User $user, FinancialTransaction $transaction): bool
    {
        return $this->update($user, $transaction);
    }
}
