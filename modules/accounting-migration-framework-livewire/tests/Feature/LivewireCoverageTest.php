<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\MigrationFrameworkLivewire\Livewire\MigrationBatches;
use Liberu\Accounting\MigrationFrameworkLivewire\MigrationFrameworkLivewireServiceProvider;

it('renders accounting-migration-framework-livewire livewire components', function (): void {
    $provider = $this->app->register(MigrationFrameworkLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(MigrationFrameworkLivewireServiceProvider::class);

    expect(fn () => (new MigrationBatches())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new MigrationBatches();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
