<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\PayrollJournalsFilament\PayrollJournalsFilamentPlugin;
use Liberu\Accounting\PayrollJournalsFilament\PayrollJournalsFilamentServiceProvider;
use Liberu\Accounting\PayrollJournalsFilament\Resources\PayrollJournalResource;
use Liberu\Accounting\PayrollJournalsFilament\Resources\PayrollJournalResource\Pages\ListPayrollJournals;

it('registers and configures accounting-payroll-journals-filament filament resources', function (): void {
    $provider = $this->app->register(PayrollJournalsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(PayrollJournalsFilamentServiceProvider::class);
    $plugin = PayrollJournalsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-payroll-journals-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListPayrollJournals();
    expect(PayrollJournalResource::getPages())->toBeArray();
    expect(PayrollJournalResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(PayrollJournalResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(PayrollJournalResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
