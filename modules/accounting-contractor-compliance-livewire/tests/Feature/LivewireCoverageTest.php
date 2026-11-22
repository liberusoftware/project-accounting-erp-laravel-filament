<?php

declare(strict_types=1);

use Liberu\Accounting\ContractorComplianceLivewire\ContractorComplianceLivewireServiceProvider;

it('boots accounting-contractor-compliance-livewire service provider', function (): void {
    $provider = $this->app->register(ContractorComplianceLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(ContractorComplianceLivewireServiceProvider::class);
});
