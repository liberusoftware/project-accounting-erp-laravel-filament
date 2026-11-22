<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
    $host = new ListAnomalies();
    expect(AnomalyResource::getPages())->toBeArray();
    expect(AnomalyResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(AnomalyResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(AnomalyResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $mutated = Closure::bind(fn () => $this->mutateFormDataBeforeCreate(['name' => 'Test']), new CreateAnomaly(), CreateAnomaly::class)();
    expect($mutated)->toBeArray();
});
