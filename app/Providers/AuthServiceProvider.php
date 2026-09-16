<?php

namespace App\Providers;

use App\Models\LetterRequest;
use App\Models\WargaProfile;
use App\Models\FinancialTransaction;
use App\Policies\LetterRequestPolicy;
use App\Policies\WargaProfilePolicy;
use App\Policies\FinancialTransactionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        WargaProfile::class => WargaProfilePolicy::class,
        LetterRequest::class => LetterRequestPolicy::class,
        FinancialTransaction::class => FinancialTransactionPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
