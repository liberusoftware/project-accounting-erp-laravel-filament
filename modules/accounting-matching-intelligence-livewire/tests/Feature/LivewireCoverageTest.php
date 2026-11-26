<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\MatchingIntelligenceLivewire\Livewire\MatchingSuggestions;
use Liberu\Accounting\MatchingIntelligenceLivewire\MatchingIntelligenceLivewireServiceProvider;

it('renders accounting-matching-intelligence-livewire livewire components', function (): void {
    $provider = $this->app->register(MatchingIntelligenceLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(MatchingIntelligenceLivewireServiceProvider::class);

    expect(fn () => (new MatchingSuggestions())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new MatchingSuggestions();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = 'active';
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
    expect($component->render())->not->toBeNull();
});
