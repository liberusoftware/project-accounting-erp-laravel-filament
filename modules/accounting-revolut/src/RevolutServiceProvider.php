<?php

declare(strict_types=1);

namespace Liberu\Accounting\Revolut;

use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;

final class RevolutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RevolutAdapter::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        $adapter = $this->app->make(RevolutAdapter::class);
        $this->app->make(IntegrationRegistry::class)->register($adapter);
        $this->app->make(PaymentGatewayRegistryContract::class)->register($adapter);
    }
}
