<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\BranchLocationAccountingFilament\BranchLocationAccountingFilamentPlugin;
use Liberu\Accounting\BranchLocationAccountingFilament\BranchLocationAccountingFilamentServiceProvider;
use Liberu\Accounting\BranchLocationAccountingFilament\Resources\BranchResource;
use Liberu\Accounting\BranchLocationAccountingFilament\Resources\BranchResource\Pages\ListBranches;

it('registers and configures accounting-branch-and-location-accounting-filament filament resources', function (): void {
    $provider = $this->app->register(BranchLocationAccountingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BranchLocationAccountingFilamentServiceProvider::class);
    $plugin = BranchLocationAccountingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-branch-and-location-accounting-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListBranches();
    expect(BranchResource::getPages())->toBeArray();
    expect(BranchResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(BranchResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(BranchResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
