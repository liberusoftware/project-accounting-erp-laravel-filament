<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\ContractorReportingFilament\ContractorReportingFilamentPlugin;
use Liberu\Accounting\ContractorReportingFilament\ContractorReportingFilamentServiceProvider;

it('registers and configures accounting-contractor-reporting-filament filament resources', function (): void {
    $provider = $this->app->register(ContractorReportingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ContractorReportingFilamentServiceProvider::class);
    $plugin = ContractorReportingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-contractor-reporting-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});
