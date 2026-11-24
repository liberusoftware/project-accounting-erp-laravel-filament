<?php

declare(strict_types=1);

namespace Liberu\Accounting\Plaid;

use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;

final class PlaidServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlaidBankFeedAdapter::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        if ($this->app->bound(IntegrationRegistry::class)) {
            $registry = $this->app->make(IntegrationRegistry::class);
            if (! array_key_exists('plaid', $registry->all())) {
                $registry->register($this->app->make(PlaidBankFeedAdapter::class));
            }
        }
    }
}
