<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\InventoryAccountingFilament\InventoryAccountingFilamentPlugin;
use Liberu\Accounting\InventoryAccountingFilament\InventoryAccountingFilamentServiceProvider;
use Liberu\Accounting\InventoryAccountingFilament\Resources\InventoryItemResource;
use Liberu\Accounting\InventoryAccountingFilament\Resources\Pages\ListInventoryItems;

it('registers and configures accounting-inventory-accounting-filament filament resources', function (): void {
    $provider = $this->app->register(InventoryAccountingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(InventoryAccountingFilamentServiceProvider::class);
    $plugin = InventoryAccountingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-inventory-accounting-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListInventoryItems();
    expect(InventoryItemResource::getPages())->toBeArray();
    expect(InventoryItemResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(InventoryItemResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
