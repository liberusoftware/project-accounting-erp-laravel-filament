<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\ManagementReportingFilament\ManagementReportingFilamentPlugin;
use Liberu\Accounting\ManagementReportingFilament\ManagementReportingFilamentServiceProvider;
use Liberu\Accounting\ManagementReportingFilament\Resources\Pages\ListReportPacks;
use Liberu\Accounting\ManagementReportingFilament\Resources\ReportPackResource;

it('registers and configures accounting-management-reporting-filament filament resources', function (): void {
    $provider = $this->app->register(ManagementReportingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ManagementReportingFilamentServiceProvider::class);
    $plugin = ManagementReportingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-management-reporting-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListReportPacks();
    expect(ReportPackResource::getPages())->toBeArray();
    expect(ReportPackResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ReportPackResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
