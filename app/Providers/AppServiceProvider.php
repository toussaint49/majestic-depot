<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class);
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Le super administrateur SaaS a tous les droits, y compris hors entreprise.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);
    }
}
