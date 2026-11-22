<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\KpiAndGoalsFilament\KpiAndGoalsFilamentPlugin;
use Liberu\Accounting\KpiAndGoalsFilament\KpiAndGoalsFilamentServiceProvider;
use Liberu\Accounting\KpiAndGoalsFilament\Resources\KpiGoalResource;
use Liberu\Accounting\KpiAndGoalsFilament\Resources\Pages\ListKpiGoals;

it('registers and configures accounting-kpi-and-goals-filament filament resources', function (): void {
    $provider = $this->app->register(KpiAndGoalsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(KpiAndGoalsFilamentServiceProvider::class);
    $plugin = KpiAndGoalsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-kpi-and-goals-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListKpiGoals();
    expect(KpiGoalResource::getPages())->toBeArray();
    expect(KpiGoalResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(KpiGoalResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
