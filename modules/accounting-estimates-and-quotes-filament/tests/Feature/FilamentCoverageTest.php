<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\EstimatesAndQuotesFilament\EstimatesAndQuotesFilamentPlugin;
use Liberu\Accounting\EstimatesAndQuotesFilament\EstimatesAndQuotesFilamentServiceProvider;
use Liberu\Accounting\EstimatesAndQuotesFilament\Resources\EstimateResource;
use Liberu\Accounting\EstimatesAndQuotesFilament\Resources\Pages\ListEstimates;

it('registers and configures accounting-estimates-and-quotes-filament filament resources', function (): void {
    $provider = $this->app->register(EstimatesAndQuotesFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(EstimatesAndQuotesFilamentServiceProvider::class);
    $plugin = EstimatesAndQuotesFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-estimates-and-quotes-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListEstimates();
    expect(EstimateResource::getPages())->toBeArray();
    expect(EstimateResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(EstimateResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
