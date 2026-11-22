<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\PeriodsFilament\AccountingPeriodsFilamentPlugin;
use Liberu\Accounting\PeriodsFilament\AccountingPeriodsFilamentServiceProvider;
use Liberu\Accounting\PeriodsFilament\Resources\AccountingPeriodResource;
use Liberu\Accounting\PeriodsFilament\Resources\AccountingPeriodResource\Pages\ListAccountingPeriods;

it('registers and configures accounting-periods-filament filament resources', function (): void {
    $provider = $this->app->register(AccountingPeriodsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AccountingPeriodsFilamentServiceProvider::class);
    $plugin = AccountingPeriodsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-periods-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListAccountingPeriods();
    expect(AccountingPeriodResource::getPages())->toBeArray();
    expect(AccountingPeriodResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(AccountingPeriodResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
