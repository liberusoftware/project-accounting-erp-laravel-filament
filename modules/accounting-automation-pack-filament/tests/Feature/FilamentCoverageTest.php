<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\AutomationPackFilament\AutomationPackFilamentPlugin;
use Liberu\Accounting\AutomationPackFilament\AutomationPackFilamentServiceProvider;
use Liberu\Accounting\AutomationPackFilament\Resources\AutomationRecipeResource;
use Liberu\Accounting\AutomationPackFilament\Resources\AutomationRecipeResource\Pages\ListAutomationRecipes;

it('registers and configures accounting-automation-pack-filament filament resources', function (): void {
    $provider = $this->app->register(AutomationPackFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AutomationPackFilamentServiceProvider::class);
    $plugin = AutomationPackFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-automation-pack-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListAutomationRecipes();
    expect(AutomationRecipeResource::getPages())->toBeArray();
    expect(AutomationRecipeResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(AutomationRecipeResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(AutomationRecipeResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
