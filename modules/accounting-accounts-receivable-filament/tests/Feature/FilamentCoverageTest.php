<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\AccountsReceivableFilament\AccountsReceivableFilamentPlugin;
use Liberu\Accounting\AccountsReceivableFilament\AccountsReceivableFilamentServiceProvider;
use Liberu\Accounting\AccountsReceivableFilament\Resources\Pages\ListReceivables;
use Liberu\Accounting\AccountsReceivableFilament\Resources\ReceivableOpenItemResource;

it('registers and configures accounting-accounts-receivable-filament filament resources', function (): void {
    $provider = $this->app->register(AccountsReceivableFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AccountsReceivableFilamentServiceProvider::class);
    $plugin = AccountsReceivableFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-accounts-receivable-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListReceivables();
    expect(ReceivableOpenItemResource::getPages())->toBeArray();
    expect(ReceivableOpenItemResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ReceivableOpenItemResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
