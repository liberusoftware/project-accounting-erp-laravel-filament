<?php

declare(strict_types=1);

use Liberu\Accounting\DocumentCaptureLivewire\DocumentCaptureLivewireServiceProvider;
use Liberu\Accounting\DocumentCaptureLivewire\Livewire\Documents;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-document-capture-livewire livewire components', function (): void {
    $provider = $this->app->register(DocumentCaptureLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DocumentCaptureLivewireServiceProvider::class);

    expect(fn () => (new Documents())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Documents();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = 'active';
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
});
