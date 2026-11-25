<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\SupplierPortalFilament\Resources\PortalResourceResource;
use Liberu\Accounting\SupplierPortalFilament\Resources\PortalResourceResource\Pages\ListPortalResources;
use Liberu\Accounting\SupplierPortalFilament\SupplierPortalFilamentPlugin;
use Liberu\Accounting\SupplierPortalFilament\SupplierPortalFilamentServiceProvider;

it('registers and configures accounting-supplier-portal-filament filament resources', function (): void {
    $provider = $this->app->register(SupplierPortalFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(SupplierPortalFilamentServiceProvider::class);
    $plugin = SupplierPortalFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-supplier-portal-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListPortalResources();
    expect(PortalResourceResource::getPages())->toBeArray();
    expect(PortalResourceResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(PortalResourceResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
