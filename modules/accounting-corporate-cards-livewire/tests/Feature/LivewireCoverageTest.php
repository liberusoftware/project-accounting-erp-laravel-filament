<?php

declare(strict_types=1);

use Liberu\Accounting\CorporateCardsLivewire\CorporateCardsLivewireServiceProvider;
use Liberu\Accounting\CorporateCardsLivewire\Livewire\CardOverview;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-corporate-cards-livewire livewire components', function (): void {
    $provider = $this->app->register(CorporateCardsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CorporateCardsLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new CardOverview();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
