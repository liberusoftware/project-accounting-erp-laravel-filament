<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\ReceiptManagementFilament\ReceiptManagementFilamentPlugin;
use Liberu\Accounting\ReceiptManagementFilament\ReceiptManagementFilamentServiceProvider;
use Liberu\Accounting\ReceiptManagementFilament\Resources\Pages\ListReceipts;
use Liberu\Accounting\ReceiptManagementFilament\Resources\ReceiptResource;

it('registers and configures accounting-receipt-management-filament filament resources', function (): void {
    $provider = $this->app->register(ReceiptManagementFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ReceiptManagementFilamentServiceProvider::class);
    $plugin = ReceiptManagementFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-receipt-management-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListReceipts();
    expect(ReceiptResource::getPages())->toBeArray();
    expect(ReceiptResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(ReceiptResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ReceiptResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
