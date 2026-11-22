<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\ProjectProfitabilityFilament\ProjectProfitabilityFilamentPlugin;
use Liberu\Accounting\ProjectProfitabilityFilament\ProjectProfitabilityFilamentServiceProvider;
use Liberu\Accounting\ProjectProfitabilityFilament\Resources\Pages\ListProjectProfitability;
use Liberu\Accounting\ProjectProfitabilityFilament\Resources\ProjectProfitabilityResource;

it('registers and configures accounting-project-profitability-filament filament resources', function (): void {
    $provider = $this->app->register(ProjectProfitabilityFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ProjectProfitabilityFilamentServiceProvider::class);
    $plugin = ProjectProfitabilityFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-project-profitability-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListProjectProfitability();
    expect(ProjectProfitabilityResource::getPages())->toBeArray();
    expect(ProjectProfitabilityResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(ProjectProfitabilityResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ProjectProfitabilityResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
