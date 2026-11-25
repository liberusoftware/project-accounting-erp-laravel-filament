<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\CopilotFilament\CopilotFilamentPlugin;
use Liberu\Accounting\CopilotFilament\CopilotFilamentServiceProvider;
use Liberu\Accounting\CopilotFilament\Resources\CopilotRequestResource;
use Liberu\Accounting\CopilotFilament\Resources\CopilotRequestResource\Pages\ListCopilotRequests;

it('registers and configures accounting-copilot-filament filament resources', function (): void {
    $provider = $this->app->register(CopilotFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CopilotFilamentServiceProvider::class);
    $plugin = CopilotFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-copilot-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListCopilotRequests();
    expect(CopilotRequestResource::getPages())->toBeArray();
    expect(CopilotRequestResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(CopilotRequestResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(CopilotRequestResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
