<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\OpeningBalancesFilament\OpeningBalancesFilamentPlugin;
use Liberu\Accounting\OpeningBalancesFilament\OpeningBalancesFilamentServiceProvider;
use Liberu\Accounting\OpeningBalancesFilament\Resources\OpeningBalanceBatchResource;
use Liberu\Accounting\OpeningBalancesFilament\Resources\Pages\ListOpeningBalanceBatches;

it('registers and configures accounting-opening-balances-filament filament resources', function (): void {
    $provider = $this->app->register(OpeningBalancesFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(OpeningBalancesFilamentServiceProvider::class);
    $plugin = OpeningBalancesFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-opening-balances-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListOpeningBalanceBatches();
    expect(OpeningBalanceBatchResource::getPages())->toBeArray();
    expect(OpeningBalanceBatchResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(OpeningBalanceBatchResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
