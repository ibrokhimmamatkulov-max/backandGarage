<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;

use Carbon\Carbon;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $token_expired = Carbon::now()->addHour();
        $refresh_token_exp = Carbon::now()->addDay();
        if(app()->isProduction()) {
            $token_expired = Carbon::now()->addMonth();
            $refresh_token_exp = Carbon::now()->addMonths(3);
        }
        
        $this->registerPolicies();
        
        Passport::enablePasswordGrant();
        Passport::tokensExpireIn($token_expired);
        Passport::refreshTokensExpireIn($refresh_token_exp);
        // Вход менеджера по SMS-коду выдаёт personal access токен — без этой
        // строки он жил бы год (дефолт Passport), а не как токен по паролю.
        Passport::personalAccessTokensExpireIn($token_expired);

        Passport::tokensCan([
            'web' => 'User token',
            'billing' => 'Billing token',
            'client' => 'Client token'
        ]);
    }
}
