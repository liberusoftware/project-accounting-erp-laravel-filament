<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\EInvoicingFilament\EInvoicingFilamentPlugin;
use Liberu\Accounting\EInvoicingFilament\EInvoicingFilamentServiceProvider;
use Liberu\Accounting\EInvoicingFilament\Resources\EInvoiceResource;
use Liberu\Accounting\EInvoicingFilament\Resources\Pages\ListEInvoices;

it('registers and configures accounting-e-invoicing-filament filament resources', function (): void {
    $provider = $this->app->register(EInvoicingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(EInvoicingFilamentServiceProvider::class);
    $plugin = EInvoicingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-e-invoicing-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListEInvoices();
    expect(EInvoiceResource::getPages())->toBeArray();
    expect(EInvoiceResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(EInvoiceResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
