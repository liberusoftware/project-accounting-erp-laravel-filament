<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\PaymentReconciliationFilament\PaymentReconciliationFilamentPlugin;
use Liberu\Accounting\PaymentReconciliationFilament\PaymentReconciliationFilamentServiceProvider;
use Liberu\Accounting\PaymentReconciliationFilament\Resources\Pages\ListSettlementRuns;
use Liberu\Accounting\PaymentReconciliationFilament\Resources\SettlementRunResource;

it('registers and configures accounting-payment-reconciliation-filament filament resources', function (): void {
    $provider = $this->app->register(PaymentReconciliationFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(PaymentReconciliationFilamentServiceProvider::class);
    $plugin = PaymentReconciliationFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-payment-reconciliation-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListSettlementRuns();
    expect(SettlementRunResource::getPages())->toBeArray();
    expect(SettlementRunResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(SettlementRunResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
