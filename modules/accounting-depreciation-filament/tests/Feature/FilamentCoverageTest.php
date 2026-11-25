<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\DepreciationFilament\DepreciationFilamentPlugin;
use Liberu\Accounting\DepreciationFilament\DepreciationFilamentServiceProvider;
use Liberu\Accounting\DepreciationFilament\Resources\DepreciationScheduleResource;
use Liberu\Accounting\DepreciationFilament\Resources\DepreciationScheduleResource\Pages\ListDepreciationSchedules;

it('registers and configures accounting-depreciation-filament filament resources', function (): void {
    $provider = $this->app->register(DepreciationFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DepreciationFilamentServiceProvider::class);
    $plugin = DepreciationFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-depreciation-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListDepreciationSchedules();
    expect(DepreciationScheduleResource::getPages())->toBeArray();
    expect(DepreciationScheduleResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(DepreciationScheduleResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
