<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\MultiCurrencyFilament\MultiCurrencyFilamentPlugin;
use Liberu\Accounting\MultiCurrencyFilament\MultiCurrencyFilamentServiceProvider;
use Liberu\Accounting\MultiCurrencyFilament\Resources\Pages\ListRevaluations;
use Liberu\Accounting\MultiCurrencyFilament\Resources\RevaluationResource;

it('registers and configures accounting-multi-currency-filament filament resources', function (): void {
    $provider = $this->app->register(MultiCurrencyFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(MultiCurrencyFilamentServiceProvider::class);
    $plugin = MultiCurrencyFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-multi-currency-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListRevaluations();
    expect(RevaluationResource::getPages())->toBeArray();
    expect(RevaluationResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(RevaluationResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
