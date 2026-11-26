<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\AnomalyDetectionFilament\AnomalyDetectionFilamentPlugin;
use Liberu\Accounting\AnomalyDetectionFilament\AnomalyDetectionFilamentServiceProvider;
use Liberu\Accounting\AnomalyDetectionFilament\Resources\AnomalyResource;
use Liberu\Accounting\AnomalyDetectionFilament\Resources\Pages\CreateAnomaly;
use Liberu\Accounting\AnomalyDetectionFilament\Resources\Pages\ListAnomalies;

it('registers and configures accounting-anomaly-detection-filament filament resources', function (): void {
    $provider = $this->app->register(AnomalyDetectionFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AnomalyDetectionFilamentServiceProvider::class);
    $plugin = AnomalyDetectionFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-anomaly-detection-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListAnomalies();
    expect(AnomalyResource::getPages())->toBeArray();
    expect(AnomalyResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(AnomalyResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(AnomalyResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $mutated = Closure::bind(fn () => $this->mutateFormDataBeforeCreate(['name' => 'Test']), new CreateAnomaly(), CreateAnomaly::class)();
    expect($mutated)->toBeArray();
});
