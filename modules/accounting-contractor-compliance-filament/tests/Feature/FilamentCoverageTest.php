<?php

declare(strict_types=1);

use Filament\Panel;
use Liberu\Accounting\ContractorComplianceFilament\ContractorComplianceFilamentPlugin;
use Liberu\Accounting\ContractorComplianceFilament\ContractorComplianceFilamentServiceProvider;

it('registers and configures accounting-contractor-compliance-filament filament resources', function (): void {
    $provider = $this->app->register(ContractorComplianceFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ContractorComplianceFilamentServiceProvider::class);
    $plugin = ContractorComplianceFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-contractor-compliance-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});
