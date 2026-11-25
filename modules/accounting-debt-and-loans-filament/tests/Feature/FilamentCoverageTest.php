<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\DebtAndLoansFilament\DebtAndLoansFilamentPlugin;
use Liberu\Accounting\DebtAndLoansFilament\DebtAndLoansFilamentServiceProvider;
use Liberu\Accounting\DebtAndLoansFilament\Resources\DebtFacilityResource;
use Liberu\Accounting\DebtAndLoansFilament\Resources\DebtFacilityResource\Pages\ListDebtFacilities;

it('registers and configures accounting-debt-and-loans-filament filament resources', function (): void {
    $provider = $this->app->register(DebtAndLoansFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DebtAndLoansFilamentServiceProvider::class);
    $plugin = DebtAndLoansFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-debt-and-loans-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListDebtFacilities();
    expect(DebtFacilityResource::getPages())->toBeArray();
    expect(DebtFacilityResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(DebtFacilityResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
