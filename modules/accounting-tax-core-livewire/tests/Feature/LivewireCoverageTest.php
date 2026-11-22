<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\TaxCoreLivewire\Livewire\TaxRules;
use Liberu\Accounting\TaxCoreLivewire\TaxCoreLivewireServiceProvider;

it('renders accounting-tax-core-livewire livewire components', function (): void {
    $provider = $this->app->register(TaxCoreLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(TaxCoreLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new TaxRules();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
