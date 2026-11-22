<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\RevenueRecognitionFilament\Resources\Pages\ListRevenueSchedules;
use Liberu\Accounting\RevenueRecognitionFilament\Resources\RevenueScheduleResource;
use Liberu\Accounting\RevenueRecognitionFilament\RevenueRecognitionFilamentPlugin;
use Liberu\Accounting\RevenueRecognitionFilament\RevenueRecognitionFilamentServiceProvider;

it('registers and configures accounting-revenue-recognition-filament filament resources', function (): void {
    $provider = $this->app->register(RevenueRecognitionFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(RevenueRecognitionFilamentServiceProvider::class);
    $plugin = RevenueRecognitionFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-revenue-recognition-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListRevenueSchedules();
    expect(RevenueScheduleResource::getPages())->toBeArray();
    expect(RevenueScheduleResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(RevenueScheduleResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(RevenueScheduleResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
