<?php

declare(strict_types=1);

use Liberu\Accounting\ConstructionTaxLivewire\ConstructionTaxLivewireServiceProvider;

it('boots accounting-construction-tax-livewire service provider', function (): void {
    $provider = $this->app->register(ConstructionTaxLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(ConstructionTaxLivewireServiceProvider::class);
});
