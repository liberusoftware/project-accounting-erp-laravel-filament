<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\WorkpapersFilament\Resources\Pages\ListWorkpapers;
use Liberu\Accounting\WorkpapersFilament\Resources\WorkpaperResource;
use Liberu\Accounting\WorkpapersFilament\WorkpapersFilamentPlugin;
use Liberu\Accounting\WorkpapersFilament\WorkpapersFilamentServiceProvider;

it('registers and configures accounting-workpapers-filament filament resources', function (): void {
    $provider = $this->app->register(WorkpapersFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(WorkpapersFilamentServiceProvider::class);
    $plugin = WorkpapersFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-workpapers-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListWorkpapers;
    expect(WorkpaperResource::getPages())->toBeArray();
    expect(WorkpaperResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(WorkpaperResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(WorkpaperResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
