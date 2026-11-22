<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\XeroMigrationLivewire\Livewire\XeroConnections;
use Liberu\Accounting\XeroMigrationLivewire\XeroMigrationLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-xero-migration-livewire livewire components', function (): void {
    $provider = $this->app->register(XeroMigrationLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(XeroMigrationLivewireServiceProvider::class);

    expect(fn () => (new XeroConnections)->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new XeroConnections;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
});
