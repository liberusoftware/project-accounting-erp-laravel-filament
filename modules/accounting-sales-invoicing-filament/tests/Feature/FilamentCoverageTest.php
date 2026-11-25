<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\SalesInvoicingFilament\Resources\SalesInvoiceResource;
use Liberu\Accounting\SalesInvoicingFilament\Resources\SalesInvoiceResource\Pages\ListSalesInvoices;
use Liberu\Accounting\SalesInvoicingFilament\SalesInvoicingFilamentPlugin;
use Liberu\Accounting\SalesInvoicingFilament\SalesInvoicingFilamentServiceProvider;

it('registers and configures accounting-sales-invoicing-filament filament resources', function (): void {
    $provider = $this->app->register(SalesInvoicingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(SalesInvoicingFilamentServiceProvider::class);
    $plugin = SalesInvoicingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-sales-invoicing-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListSalesInvoices();
    expect(SalesInvoiceResource::getPages())->toBeArray();
    expect(SalesInvoiceResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(SalesInvoiceResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
