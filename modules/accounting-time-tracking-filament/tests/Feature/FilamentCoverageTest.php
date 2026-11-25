<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\TimeTrackingFilament\Resources\Pages\ListTimeEntries;
use Liberu\Accounting\TimeTrackingFilament\Resources\TimeEntryResource;
use Liberu\Accounting\TimeTrackingFilament\TimeTrackingFilamentPlugin;
use Liberu\Accounting\TimeTrackingFilament\TimeTrackingFilamentServiceProvider;

it('registers and configures accounting-time-tracking-filament filament resources', function (): void {
    $provider = $this->app->register(TimeTrackingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(TimeTrackingFilamentServiceProvider::class);
    $plugin = TimeTrackingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-time-tracking-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListTimeEntries();
    expect(TimeEntryResource::getPages())->toBeArray();
    expect(TimeEntryResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(TimeEntryResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(TimeEntryResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
