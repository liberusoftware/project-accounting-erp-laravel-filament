<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\CarbonAccountingFilament\CarbonAccountingFilamentPlugin;
use Liberu\Accounting\CarbonAccountingFilament\CarbonAccountingFilamentServiceProvider;
use Liberu\Accounting\CarbonAccountingFilament\Resources\CarbonActivityResource;
use Liberu\Accounting\CarbonAccountingFilament\Resources\Pages\CreateCarbonActivity;
use Liberu\Accounting\CarbonAccountingFilament\Resources\Pages\ListCarbonActivities;

it('registers and configures accounting-carbon-accounting-filament filament resources', function (): void {
    $provider = $this->app->register(CarbonAccountingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CarbonAccountingFilamentServiceProvider::class);
    $plugin = CarbonAccountingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-carbon-accounting-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListCarbonActivities();
    expect(CarbonActivityResource::getPages())->toBeArray();
    expect(CarbonActivityResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(CarbonActivityResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(CarbonActivityResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $mutated = Closure::bind(fn () => $this->mutateFormDataBeforeCreate(['name' => 'Test']), new CreateCarbonActivity(), CreateCarbonActivity::class)();
    expect($mutated)->toBeArray();
});
