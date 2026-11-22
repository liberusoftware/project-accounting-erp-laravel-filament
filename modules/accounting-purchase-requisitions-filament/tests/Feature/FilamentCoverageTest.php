<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\PurchaseRequisitionsFilament\PurchaseRequisitionsFilamentPlugin;
use Liberu\Accounting\PurchaseRequisitionsFilament\PurchaseRequisitionsFilamentServiceProvider;
use Liberu\Accounting\PurchaseRequisitionsFilament\Resources\PurchaseRequisitionResource;
use Liberu\Accounting\PurchaseRequisitionsFilament\Resources\PurchaseRequisitionResource\Pages\ListPurchaseRequisitions;

it('registers and configures accounting-purchase-requisitions-filament filament resources', function (): void {
    $provider = $this->app->register(PurchaseRequisitionsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(PurchaseRequisitionsFilamentServiceProvider::class);
    $plugin = PurchaseRequisitionsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-purchase-requisitions-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListPurchaseRequisitions;
    expect(PurchaseRequisitionResource::getPages())->toBeArray();
    expect(PurchaseRequisitionResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(PurchaseRequisitionResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(PurchaseRequisitionResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
