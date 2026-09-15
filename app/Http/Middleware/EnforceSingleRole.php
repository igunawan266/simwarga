<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnforceSingleRole
{
    public function handle(Request $request, Closure $next)
    {
        if (auth()->check()) {
            $user = auth()->user();
            $roleCount = $user->roles()->count();

            if ($roleCount > 1) {
                auth()->logout();
                return response()->json([
                    'error' => 'User has multiple roles assigned. Contact administrator.',
                ], 403);
            }

            if ($roleCount === 0) {
                // Assign default 'warga' role if none exists
                $wargaRole = \App\Models\Role::where('name', 'warga')->first();
                if ($wargaRole) {
                    $user->roles()->attach($wargaRole);
                }
            }
        }

        return $next($request);
    }
}
