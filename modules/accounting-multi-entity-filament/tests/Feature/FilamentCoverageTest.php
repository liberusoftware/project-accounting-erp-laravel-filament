<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\MultiEntityFilament\MultiEntityFilamentPlugin;
use Liberu\Accounting\MultiEntityFilament\MultiEntityFilamentServiceProvider;
use Liberu\Accounting\MultiEntityFilament\Resources\EntityBookResource;
use Liberu\Accounting\MultiEntityFilament\Resources\Pages\ListEntityBooks;

it('registers and configures accounting-multi-entity-filament filament resources', function (): void {
    $provider = $this->app->register(MultiEntityFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(MultiEntityFilamentServiceProvider::class);
    $plugin = MultiEntityFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-multi-entity-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListEntityBooks();
    expect(EntityBookResource::getPages())->toBeArray();
    expect(EntityBookResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(EntityBookResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
