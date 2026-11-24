<?php

declare(strict_types=1);

namespace Liberu\Accounting\Wise;

use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;

final class WiseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WiseBankFeedAdapter::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        $adapter = $this->app->make(WiseBankFeedAdapter::class);
        $this->app->make(IntegrationRegistry::class)->register($adapter);
        $this->app->make(PaymentGatewayRegistryContract::class)->register($adapter);
    }
}
