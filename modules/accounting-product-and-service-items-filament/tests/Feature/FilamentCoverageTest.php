<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\ProductAndServiceItemsFilament\ProductAndServiceItemsFilamentPlugin;
use Liberu\Accounting\ProductAndServiceItemsFilament\ProductAndServiceItemsFilamentServiceProvider;
use Liberu\Accounting\ProductAndServiceItemsFilament\Resources\AccountingItemResource;
use Liberu\Accounting\ProductAndServiceItemsFilament\Resources\AccountingItemResource\Pages\ListAccountingItems;

it('registers and configures accounting-product-and-service-items-filament filament resources', function (): void {
    $provider = $this->app->register(ProductAndServiceItemsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ProductAndServiceItemsFilamentServiceProvider::class);
    $plugin = ProductAndServiceItemsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-product-and-service-items-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListAccountingItems();
    expect(AccountingItemResource::getPages())->toBeArray();
    expect(AccountingItemResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(AccountingItemResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(AccountingItemResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
