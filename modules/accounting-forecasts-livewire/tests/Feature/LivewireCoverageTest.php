<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ForecastsLivewire\ForecastsLivewireServiceProvider;
use Liberu\Accounting\ForecastsLivewire\Livewire\Forecasts;

it('renders accounting-forecasts-livewire livewire components', function (): void {
    $provider = $this->app->register(ForecastsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ForecastsLivewireServiceProvider::class);

    expect(fn () => (new Forecasts())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Forecasts();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = 'active';
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
});
