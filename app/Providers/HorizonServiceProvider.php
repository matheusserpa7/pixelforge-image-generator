<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Only admins open the dashboard, in every environment. The default provider
     * lets anyone in when APP_ENV=local; we do not.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (Request $request): bool => Gate::forUser($request->user())->allows('viewHorizon'));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user !== null && Gate::forUser($user)->allows('admin'));
    }
}
