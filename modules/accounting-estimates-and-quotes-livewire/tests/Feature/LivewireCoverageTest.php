<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\EstimatesAndQuotesLivewire\EstimatesAndQuotesLivewireServiceProvider;
use Liberu\Accounting\EstimatesAndQuotesLivewire\Livewire\Estimates;

it('renders accounting-estimates-and-quotes-livewire livewire components', function (): void {
    $provider = $this->app->register(EstimatesAndQuotesLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(EstimatesAndQuotesLivewireServiceProvider::class);

    expect(fn () => (new Estimates())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Estimates();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = 'active';
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
});
