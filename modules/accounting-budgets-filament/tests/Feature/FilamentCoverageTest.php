<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\BudgetsFilament\BudgetsFilamentPlugin;
use Liberu\Accounting\BudgetsFilament\BudgetsFilamentServiceProvider;
use Liberu\Accounting\BudgetsFilament\Resources\BudgetResource;
use Liberu\Accounting\BudgetsFilament\Resources\Pages\CreateBudget;
use Liberu\Accounting\BudgetsFilament\Resources\Pages\ListBudgets;

it('registers and configures accounting-budgets-filament filament resources', function (): void {
    $provider = $this->app->register(BudgetsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BudgetsFilamentServiceProvider::class);
    $plugin = BudgetsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-budgets-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListBudgets();
    expect(BudgetResource::getPages())->toBeArray();
    expect(BudgetResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(BudgetResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(BudgetResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $mutated = Closure::bind(fn () => $this->mutateFormDataBeforeCreate(['name' => 'Test']), new CreateBudget(), CreateBudget::class)();
    expect($mutated)->toBeArray();
});
