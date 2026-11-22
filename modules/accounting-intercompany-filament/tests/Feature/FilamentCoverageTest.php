<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\IntercompanyFilament\IntercompanyFilamentPlugin;
use Liberu\Accounting\IntercompanyFilament\IntercompanyFilamentServiceProvider;
use Liberu\Accounting\IntercompanyFilament\Resources\IntercompanyTransactionResource;
use Liberu\Accounting\IntercompanyFilament\Resources\Pages\ListIntercompanyTransactions;

it('registers and configures accounting-intercompany-filament filament resources', function (): void {
    $provider = $this->app->register(IntercompanyFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(IntercompanyFilamentServiceProvider::class);
    $plugin = IntercompanyFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-intercompany-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListIntercompanyTransactions();
    expect(IntercompanyTransactionResource::getPages())->toBeArray();
    expect(IntercompanyTransactionResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(IntercompanyTransactionResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
