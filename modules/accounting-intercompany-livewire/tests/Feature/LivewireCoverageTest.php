<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\IntercompanyLivewire\IntercompanyLivewireServiceProvider;
use Liberu\Accounting\IntercompanyLivewire\Livewire\Intercompany;

it('renders accounting-intercompany-livewire livewire components', function (): void {
    $provider = $this->app->register(IntercompanyLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(IntercompanyLivewireServiceProvider::class);

    expect(fn () => (new Intercompany())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Intercompany();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = 'active';
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
});
