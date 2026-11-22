<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\WorkforceCostingFilament\Resources\Pages\ListWorkforceCostingRules;
use Liberu\Accounting\WorkforceCostingFilament\Resources\WorkforceCostingRuleResource;
use Liberu\Accounting\WorkforceCostingFilament\Resources\WorkforceCostResource;
use Liberu\Accounting\WorkforceCostingFilament\WorkforceCostingFilamentPlugin;
use Liberu\Accounting\WorkforceCostingFilament\WorkforceCostingFilamentServiceProvider;

it('registers and configures accounting-workforce-costing-filament filament resources', function (): void {
    $provider = $this->app->register(WorkforceCostingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(WorkforceCostingFilamentServiceProvider::class);
    $plugin = WorkforceCostingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-workforce-costing-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListWorkforceCostingRules();
    expect(WorkforceCostResource::getPages())->toBeArray();
    expect(WorkforceCostResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(WorkforceCostResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(WorkforceCostResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    expect(WorkforceCostingRuleResource::getPages())->toBeArray();
    expect(WorkforceCostingRuleResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(WorkforceCostingRuleResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(WorkforceCostingRuleResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
