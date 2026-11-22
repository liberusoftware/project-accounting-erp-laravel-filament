<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\SpreadsheetMigrationLivewire\Livewire\MigrationTemplates;
use Liberu\Accounting\SpreadsheetMigrationLivewire\SpreadsheetMigrationLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-spreadsheet-migration-livewire livewire components', function (): void {
    $provider = $this->app->register(SpreadsheetMigrationLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(SpreadsheetMigrationLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $test = Livewire::test(MigrationTemplates::class)->assertOk();
});
