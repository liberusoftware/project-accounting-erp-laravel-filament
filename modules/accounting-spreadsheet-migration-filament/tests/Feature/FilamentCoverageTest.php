<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\SpreadsheetMigrationFilament\Resources\MigrationTemplateResource;
use Liberu\Accounting\SpreadsheetMigrationFilament\Resources\MigrationTemplateResource\Pages\ListMigrationTemplates;
use Liberu\Accounting\SpreadsheetMigrationFilament\SpreadsheetMigrationFilamentPlugin;
use Liberu\Accounting\SpreadsheetMigrationFilament\SpreadsheetMigrationFilamentServiceProvider;

it('registers and configures accounting-spreadsheet-migration-filament filament resources', function (): void {
    $provider = $this->app->register(SpreadsheetMigrationFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(SpreadsheetMigrationFilamentServiceProvider::class);
    $plugin = SpreadsheetMigrationFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-spreadsheet-migration-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListMigrationTemplates();
    expect(MigrationTemplateResource::getPages())->toBeArray();
    expect(MigrationTemplateResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(MigrationTemplateResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
