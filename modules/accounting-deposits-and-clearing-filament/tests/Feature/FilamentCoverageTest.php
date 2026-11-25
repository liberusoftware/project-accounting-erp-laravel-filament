<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\DepositsAndClearingFilament\DepositsAndClearingFilamentPlugin;
use Liberu\Accounting\DepositsAndClearingFilament\DepositsAndClearingFilamentServiceProvider;
use Liberu\Accounting\DepositsAndClearingFilament\Resources\ClearingDepositResource;
use Liberu\Accounting\DepositsAndClearingFilament\Resources\ClearingDepositResource\Pages\ListClearingDeposits;

it('registers and configures accounting-deposits-and-clearing-filament filament resources', function (): void {
    $provider = $this->app->register(DepositsAndClearingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DepositsAndClearingFilamentServiceProvider::class);
    $plugin = DepositsAndClearingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-deposits-and-clearing-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListClearingDeposits();
    expect(ClearingDepositResource::getPages())->toBeArray();
    expect(ClearingDepositResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ClearingDepositResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
