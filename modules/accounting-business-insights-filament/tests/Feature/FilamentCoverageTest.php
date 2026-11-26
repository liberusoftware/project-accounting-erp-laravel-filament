<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\BusinessInsightsFilament\BusinessInsightsFilamentPlugin;
use Liberu\Accounting\BusinessInsightsFilament\BusinessInsightsFilamentServiceProvider;
use Liberu\Accounting\BusinessInsightsFilament\Resources\InsightSnapshotResource;
use Liberu\Accounting\BusinessInsightsFilament\Resources\Pages\CreateInsightSnapshot;
use Liberu\Accounting\BusinessInsightsFilament\Resources\Pages\ListInsightSnapshots;

it('registers and configures accounting-business-insights-filament filament resources', function (): void {
    $provider = $this->app->register(BusinessInsightsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BusinessInsightsFilamentServiceProvider::class);
    $plugin = BusinessInsightsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-business-insights-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListInsightSnapshots();
    expect(InsightSnapshotResource::getPages())->toBeArray();
    expect(InsightSnapshotResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(InsightSnapshotResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(InsightSnapshotResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $mutated = Closure::bind(fn () => $this->mutateFormDataBeforeCreate(['name' => 'Test']), new CreateInsightSnapshot(), CreateInsightSnapshot::class)();
    expect($mutated)->toBeArray();
});
