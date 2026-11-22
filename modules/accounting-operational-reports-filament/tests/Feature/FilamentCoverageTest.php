<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\OperationalReportsFilament\OperationalReportsFilamentPlugin;
use Liberu\Accounting\OperationalReportsFilament\OperationalReportsFilamentServiceProvider;
use Liberu\Accounting\OperationalReportsFilament\Resources\Pages\ListReportRuns;
use Liberu\Accounting\OperationalReportsFilament\Resources\ReportRunResource;

it('registers and configures accounting-operational-reports-filament filament resources', function (): void {
    $provider = $this->app->register(OperationalReportsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(OperationalReportsFilamentServiceProvider::class);
    $plugin = OperationalReportsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-operational-reports-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListReportRuns();
    expect(ReportRunResource::getPages())->toBeArray();
    expect(ReportRunResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ReportRunResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
