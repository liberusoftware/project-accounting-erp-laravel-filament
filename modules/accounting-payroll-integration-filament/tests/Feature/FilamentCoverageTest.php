<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\PayrollIntegrationFilament\PayrollIntegrationFilamentPlugin;
use Liberu\Accounting\PayrollIntegrationFilament\PayrollIntegrationFilamentServiceProvider;
use Liberu\Accounting\PayrollIntegrationFilament\Resources\PayrollImportResource;
use Liberu\Accounting\PayrollIntegrationFilament\Resources\PayrollImportResource\Pages\ListPayrollImports;

it('registers and configures accounting-payroll-integration-filament filament resources', function (): void {
    $provider = $this->app->register(PayrollIntegrationFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(PayrollIntegrationFilamentServiceProvider::class);
    $plugin = PayrollIntegrationFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-payroll-integration-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListPayrollImports();
    expect(PayrollImportResource::getPages())->toBeArray();
    expect(PayrollImportResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(PayrollImportResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(PayrollImportResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
