<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
    $host = new ListCarbonActivities();
    expect(CarbonActivityResource::getPages())->toBeArray();
    expect(CarbonActivityResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(CarbonActivityResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(CarbonActivityResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $mutated = Closure::bind(fn () => $this->mutateFormDataBeforeCreate(['name' => 'Test']), new CreateCarbonActivity(), CreateCarbonActivity::class)();
    expect($mutated)->toBeArray();
});
