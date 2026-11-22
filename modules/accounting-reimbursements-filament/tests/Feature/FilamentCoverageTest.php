<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\ReimbursementsFilament\ReimbursementsFilamentPlugin;
use Liberu\Accounting\ReimbursementsFilament\ReimbursementsFilamentServiceProvider;
use Liberu\Accounting\ReimbursementsFilament\Resources\Pages\ListReimbursementLiabilities;
use Liberu\Accounting\ReimbursementsFilament\Resources\ReimbursementLiabilityResource;

it('registers and configures accounting-reimbursements-filament filament resources', function (): void {
    $provider = $this->app->register(ReimbursementsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ReimbursementsFilamentServiceProvider::class);
    $plugin = ReimbursementsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-reimbursements-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListReimbursementLiabilities();
    expect(ReimbursementLiabilityResource::getPages())->toBeArray();
    expect(ReimbursementLiabilityResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(ReimbursementLiabilityResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ReimbursementLiabilityResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
