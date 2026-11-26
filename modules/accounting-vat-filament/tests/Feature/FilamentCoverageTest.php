<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\VatFilament\Resources\Pages\ListVatRecords;
use Liberu\Accounting\VatFilament\Resources\Pages\ListVatReturns;
use Liberu\Accounting\VatFilament\Resources\VatRecordResource;
use Liberu\Accounting\VatFilament\Resources\VatReturnResource;
use Liberu\Accounting\VatFilament\VatFilamentPlugin;
use Liberu\Accounting\VatFilament\VatFilamentServiceProvider;

it('registers and configures accounting-vat-filament filament resources', function (): void {
    $provider = $this->app->register(VatFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(VatFilamentServiceProvider::class);
    expect($this->app->make(VatFilamentPlugin::class))->toBeInstanceOf(VatFilamentPlugin::class);
    $plugin = VatFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-vat-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListVatRecords();
    expect(VatRecordResource::getPages())->toBeArray();
    expect(VatRecordResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(VatRecordResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(VatRecordResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    expect(VatReturnResource::getPages())->toBeArray();
    expect(VatReturnResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(VatReturnResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(VatReturnResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    expect(new ListVatReturns())->toBeInstanceOf(ListVatReturns::class);
});
