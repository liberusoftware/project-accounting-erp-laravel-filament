<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\PayrollLiabilitiesFilament\PayrollLiabilitiesFilamentPlugin;
use Liberu\Accounting\PayrollLiabilitiesFilament\PayrollLiabilitiesFilamentServiceProvider;
use Liberu\Accounting\PayrollLiabilitiesFilament\Resources\PayrollLiabilityResource;
use Liberu\Accounting\PayrollLiabilitiesFilament\Resources\PayrollLiabilityResource\Pages\ListPayrollLiabilities;

it('registers and configures accounting-payroll-liabilities-filament filament resources', function (): void {
    $provider = $this->app->register(PayrollLiabilitiesFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(PayrollLiabilitiesFilamentServiceProvider::class);
    $plugin = PayrollLiabilitiesFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-payroll-liabilities-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListPayrollLiabilities();
    expect(PayrollLiabilityResource::getPages())->toBeArray();
    expect(PayrollLiabilityResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(PayrollLiabilityResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(PayrollLiabilityResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
