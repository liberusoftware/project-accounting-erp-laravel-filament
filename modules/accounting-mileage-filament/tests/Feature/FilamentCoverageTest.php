<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\MileageFilament\MileageFilamentPlugin;
use Liberu\Accounting\MileageFilament\MileageFilamentServiceProvider;
use Liberu\Accounting\MileageFilament\Resources\MileageTripResource;
use Liberu\Accounting\MileageFilament\Resources\Pages\ListMileageTrips;

it('registers and configures accounting-mileage-filament filament resources', function (): void {
    $provider = $this->app->register(MileageFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(MileageFilamentServiceProvider::class);
    $plugin = MileageFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-mileage-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListMileageTrips();
    expect(MileageTripResource::getPages())->toBeArray();
    expect(MileageTripResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(MileageTripResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
