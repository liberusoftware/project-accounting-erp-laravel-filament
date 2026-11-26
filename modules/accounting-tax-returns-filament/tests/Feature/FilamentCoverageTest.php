<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\TaxReturnsFilament\Resources\Pages\ListTaxReturns;
use Liberu\Accounting\TaxReturnsFilament\Resources\TaxReturnResource;
use Liberu\Accounting\TaxReturnsFilament\TaxReturnsFilamentPlugin;
use Liberu\Accounting\TaxReturnsFilament\TaxReturnsFilamentServiceProvider;

it('registers and configures accounting-tax-returns-filament filament resources', function (): void {
    $provider = $this->app->register(TaxReturnsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(TaxReturnsFilamentServiceProvider::class);
    expect($this->app->make(TaxReturnsFilamentPlugin::class))->toBeInstanceOf(TaxReturnsFilamentPlugin::class);
    $plugin = TaxReturnsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-tax-returns-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListTaxReturns();
    expect(TaxReturnResource::getPages())->toBeArray();
    expect(TaxReturnResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(TaxReturnResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(TaxReturnResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
