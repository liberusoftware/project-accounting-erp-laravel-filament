<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\MigrationFrameworkFilament\MigrationFrameworkFilamentPlugin;
use Liberu\Accounting\MigrationFrameworkFilament\MigrationFrameworkFilamentServiceProvider;
use Liberu\Accounting\MigrationFrameworkFilament\Resources\MigrationBatchResource;
use Liberu\Accounting\MigrationFrameworkFilament\Resources\Pages\ListMigrationBatches;

it('registers and configures accounting-migration-framework-filament filament resources', function (): void {
    $provider = $this->app->register(MigrationFrameworkFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(MigrationFrameworkFilamentServiceProvider::class);
    $plugin = MigrationFrameworkFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-migration-framework-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListMigrationBatches();
    expect(MigrationBatchResource::getPages())->toBeArray();
    expect(MigrationBatchResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(MigrationBatchResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
