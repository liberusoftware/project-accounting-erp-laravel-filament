<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\CustomReportBuilderFilament\CustomReportBuilderFilamentPlugin;
use Liberu\Accounting\CustomReportBuilderFilament\CustomReportBuilderFilamentServiceProvider;

it('registers and configures accounting-custom-report-builder-filament filament resources', function (): void {
    $provider = $this->app->register(CustomReportBuilderFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CustomReportBuilderFilamentServiceProvider::class);
    $plugin = CustomReportBuilderFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-custom-report-builder-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});
