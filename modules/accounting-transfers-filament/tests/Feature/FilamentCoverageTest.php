<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\TransfersFilament\Resources\Pages\ListTransfers;
use Liberu\Accounting\TransfersFilament\Resources\TransferResource;
use Liberu\Accounting\TransfersFilament\TransfersFilamentPlugin;
use Liberu\Accounting\TransfersFilament\TransfersFilamentServiceProvider;

it('registers and configures accounting-transfers-filament filament resources', function (): void {
    $provider = $this->app->register(TransfersFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(TransfersFilamentServiceProvider::class);
    $plugin = TransfersFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-transfers-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListTransfers;
    expect(TransferResource::getPages())->toBeArray();
    expect(TransferResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(TransferResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(TransferResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
