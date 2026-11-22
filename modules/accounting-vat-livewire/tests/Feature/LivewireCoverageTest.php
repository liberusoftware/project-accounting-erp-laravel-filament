<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\VatLivewire\Livewire\VatRecords;
use Liberu\Accounting\VatLivewire\VatLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-vat-livewire livewire components', function (): void {
    $provider = $this->app->register(VatLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(VatLivewireServiceProvider::class);

    expect(fn () => (new VatRecords)->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new VatRecords;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
});
