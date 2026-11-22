<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\MileageLivewire\Livewire\MileageTrips;
use Liberu\Accounting\MileageLivewire\MileageLivewireServiceProvider;

it('renders accounting-mileage-livewire livewire components', function (): void {
    $provider = $this->app->register(MileageLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(MileageLivewireServiceProvider::class);

    expect(fn () => (new MileageTrips())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new MileageTrips();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->region = 'active';
    if (method_exists($component, 'updated'.ucfirst('region'))) {
        $component->{'updated'.ucfirst('region')}();
    }
});
