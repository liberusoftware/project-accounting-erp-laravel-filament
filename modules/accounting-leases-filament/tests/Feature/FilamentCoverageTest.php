<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\LeasesFilament\LeasesFilamentPlugin;
use Liberu\Accounting\LeasesFilament\LeasesFilamentServiceProvider;
use Liberu\Accounting\LeasesFilament\Resources\LeaseResource;
use Liberu\Accounting\LeasesFilament\Resources\Pages\ListLeases;

it('registers and configures accounting-leases-filament filament resources', function (): void {
    $provider = $this->app->register(LeasesFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(LeasesFilamentServiceProvider::class);
    $plugin = LeasesFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-leases-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListLeases();
    expect(LeaseResource::getPages())->toBeArray();
    expect(LeaseResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(LeaseResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
