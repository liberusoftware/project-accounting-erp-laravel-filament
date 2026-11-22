<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\SalesOrdersFilament\Resources\SalesOrderResource;
use Liberu\Accounting\SalesOrdersFilament\Resources\SalesOrderResource\Pages\ListSalesOrders;
use Liberu\Accounting\SalesOrdersFilament\SalesOrdersFilamentPlugin;
use Liberu\Accounting\SalesOrdersFilament\SalesOrdersFilamentServiceProvider;

it('registers and configures accounting-sales-orders-filament filament resources', function (): void {
    $provider = $this->app->register(SalesOrdersFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(SalesOrdersFilamentServiceProvider::class);
    $plugin = SalesOrdersFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-sales-orders-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListSalesOrders;
    expect(SalesOrderResource::getPages())->toBeArray();
    expect(SalesOrderResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(SalesOrderResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(SalesOrderResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
