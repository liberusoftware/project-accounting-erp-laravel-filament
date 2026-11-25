<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\AssetEventsFilament\AssetEventsFilamentServiceProvider;
use Liberu\Accounting\AssetEventsFilament\Resources\AssetEventResource;
use Liberu\Accounting\AssetEventsFilament\Resources\Pages\CreateAssetEvent;
use Liberu\Accounting\AssetEventsFilament\Resources\Pages\ListAssetEvents;

it('registers and configures accounting-asset-events-filament filament resources', function (): void {
    $provider = $this->app->register(AssetEventsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AssetEventsFilamentServiceProvider::class);
    $host = new ListAssetEvents();
    expect(AssetEventResource::getPages())->toBeArray();
    expect(AssetEventResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(AssetEventResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(AssetEventResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $mutated = Closure::bind(fn () => $this->mutateFormDataBeforeCreate(['name' => 'Test']), new CreateAssetEvent(), CreateAssetEvent::class)();
    expect($mutated)->toBeArray();
});
