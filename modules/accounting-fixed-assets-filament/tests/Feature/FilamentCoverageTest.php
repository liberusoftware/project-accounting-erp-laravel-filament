<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\FixedAssetsFilament\FixedAssetsFilamentPlugin;
use Liberu\Accounting\FixedAssetsFilament\FixedAssetsFilamentServiceProvider;
use Liberu\Accounting\FixedAssetsFilament\Resources\AssetResource;
use Liberu\Accounting\FixedAssetsFilament\Resources\Pages\ListAssets;

it('registers and configures accounting-fixed-assets-filament filament resources', function (): void {
    $provider = $this->app->register(FixedAssetsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(FixedAssetsFilamentServiceProvider::class);
    $plugin = FixedAssetsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-fixed-assets-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListAssets();
    expect(AssetResource::getPages())->toBeArray();
    expect(AssetResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(AssetResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
