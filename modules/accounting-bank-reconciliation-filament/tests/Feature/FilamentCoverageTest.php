<?php

declare(strict_types=1);

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Panel;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Liberu\Accounting\BankReconciliationFilament\BankReconciliationFilamentPlugin;
use Liberu\Accounting\BankReconciliationFilament\BankReconciliationFilamentServiceProvider;
use Liberu\Accounting\BankReconciliationFilament\Resources\ReconciliationSessionResource;
use Livewire\Component;

it('registers and configures accounting-bank-reconciliation-filament filament resources', function (): void {
    $provider = $this->app->register(BankReconciliationFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BankReconciliationFilamentServiceProvider::class);
    $plugin = BankReconciliationFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-bank-reconciliation-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new class() extends Component implements HasActions, HasSchemas, HasTable
    {
        use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;
    };
    expect(ReconciliationSessionResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ReconciliationSessionResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
