<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\ProjectCostingFilament\ProjectCostingFilamentPlugin;
use Liberu\Accounting\ProjectCostingFilament\ProjectCostingFilamentServiceProvider;
use Liberu\Accounting\ProjectCostingFilament\Resources\Pages\ListProjectCosts;
use Liberu\Accounting\ProjectCostingFilament\Resources\ProjectCostResource;

it('registers and configures accounting-project-costing-filament filament resources', function (): void {
    $provider = $this->app->register(ProjectCostingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ProjectCostingFilamentServiceProvider::class);
    $plugin = ProjectCostingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-project-costing-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListProjectCosts();
    expect(ProjectCostResource::getPages())->toBeArray();
    expect(ProjectCostResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(ProjectCostResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ProjectCostResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
