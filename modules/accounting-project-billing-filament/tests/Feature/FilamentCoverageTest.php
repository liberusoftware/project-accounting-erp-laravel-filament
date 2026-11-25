<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\ProjectBillingFilament\ProjectBillingFilamentPlugin;
use Liberu\Accounting\ProjectBillingFilament\ProjectBillingFilamentServiceProvider;
use Liberu\Accounting\ProjectBillingFilament\Resources\Pages\ListProjectBilling;
use Liberu\Accounting\ProjectBillingFilament\Resources\ProjectBillingResource;

it('registers and configures accounting-project-billing-filament filament resources', function (): void {
    $provider = $this->app->register(ProjectBillingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ProjectBillingFilamentServiceProvider::class);
    $plugin = ProjectBillingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-project-billing-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListProjectBilling();
    expect(ProjectBillingResource::getPages())->toBeArray();
    expect(ProjectBillingResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(ProjectBillingResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ProjectBillingResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
