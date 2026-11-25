<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\PurchaseOrdersFilament\PurchaseOrdersFilamentPlugin;
use Liberu\Accounting\PurchaseOrdersFilament\PurchaseOrdersFilamentServiceProvider;
use Liberu\Accounting\PurchaseOrdersFilament\Resources\PurchaseOrderResource;
use Liberu\Accounting\PurchaseOrdersFilament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;

it('registers and configures accounting-purchase-orders-filament filament resources', function (): void {
    $provider = $this->app->register(PurchaseOrdersFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(PurchaseOrdersFilamentServiceProvider::class);
    $plugin = PurchaseOrdersFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-purchase-orders-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListPurchaseOrders();
    expect(PurchaseOrderResource::getPages())->toBeArray();
    expect(PurchaseOrderResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(PurchaseOrderResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(PurchaseOrderResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
