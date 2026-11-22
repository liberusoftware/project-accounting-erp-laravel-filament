<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\WithholdingTaxLivewire\Livewire\WithholdingTaxRules;
use Liberu\Accounting\WithholdingTaxLivewire\WithholdingTaxLivewireServiceProvider;

it('renders accounting-withholding-tax-livewire livewire components', function (): void {
    $provider = $this->app->register(WithholdingTaxLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(WithholdingTaxLivewireServiceProvider::class);

    expect(fn () => (new WithholdingTaxRules())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new WithholdingTaxRules();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
