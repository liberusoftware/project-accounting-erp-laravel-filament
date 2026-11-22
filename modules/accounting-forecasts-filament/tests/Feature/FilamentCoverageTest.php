<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\ForecastsFilament\ForecastsFilamentPlugin;
use Liberu\Accounting\ForecastsFilament\ForecastsFilamentServiceProvider;
use Liberu\Accounting\ForecastsFilament\Resources\ForecastResource;
use Liberu\Accounting\ForecastsFilament\Resources\Pages\ListForecasts;

it('registers and configures accounting-forecasts-filament filament resources', function (): void {
    $provider = $this->app->register(ForecastsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ForecastsFilamentServiceProvider::class);
    $plugin = ForecastsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-forecasts-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListForecasts();
    expect(ForecastResource::getPages())->toBeArray();
    expect(ForecastResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ForecastResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
