<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\YearEndFilament\Resources\Pages\ListYearEndCloses;
use Liberu\Accounting\YearEndFilament\Resources\YearEndCloseResource;
use Liberu\Accounting\YearEndFilament\YearEndFilamentPlugin;
use Liberu\Accounting\YearEndFilament\YearEndFilamentServiceProvider;

it('registers and configures accounting-year-end-filament filament resources', function (): void {
    $provider = $this->app->register(YearEndFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(YearEndFilamentServiceProvider::class);
    $plugin = YearEndFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-year-end-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListYearEndCloses();
    expect(YearEndCloseResource::getPages())->toBeArray();
    expect(YearEndCloseResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(YearEndCloseResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(YearEndCloseResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
