<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\XeroMigrationFilament\Resources\Pages\ListXeroConnections;
use Liberu\Accounting\XeroMigrationFilament\Resources\XeroConnectionResource;
use Liberu\Accounting\XeroMigrationFilament\XeroMigrationFilamentPlugin;
use Liberu\Accounting\XeroMigrationFilament\XeroMigrationFilamentServiceProvider;

it('registers and configures accounting-xero-migration-filament filament resources', function (): void {
    $provider = $this->app->register(XeroMigrationFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(XeroMigrationFilamentServiceProvider::class);
    $plugin = XeroMigrationFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-xero-migration-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListXeroConnections();
    expect(XeroConnectionResource::getPages())->toBeArray();
    expect(XeroConnectionResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(XeroConnectionResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(XeroConnectionResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
