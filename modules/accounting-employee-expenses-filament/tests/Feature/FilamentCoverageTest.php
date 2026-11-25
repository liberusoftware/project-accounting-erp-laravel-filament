<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\EmployeeExpensesFilament\EmployeeExpensesFilamentPlugin;
use Liberu\Accounting\EmployeeExpensesFilament\EmployeeExpensesFilamentServiceProvider;
use Liberu\Accounting\EmployeeExpensesFilament\Resources\ExpenseClaimResource;
use Liberu\Accounting\EmployeeExpensesFilament\Resources\Pages\ListExpenseClaims;

it('registers and configures accounting-employee-expenses-filament filament resources', function (): void {
    $provider = $this->app->register(EmployeeExpensesFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(EmployeeExpensesFilamentServiceProvider::class);
    $plugin = EmployeeExpensesFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-employee-expenses-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListExpenseClaims();
    expect(ExpenseClaimResource::getPages())->toBeArray();
    expect(ExpenseClaimResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ExpenseClaimResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
