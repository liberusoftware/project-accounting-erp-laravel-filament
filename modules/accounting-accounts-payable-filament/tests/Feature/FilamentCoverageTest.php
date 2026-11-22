<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\AccountsPayableFilament\AccountsPayableFilamentPlugin;
use Liberu\Accounting\AccountsPayableFilament\AccountsPayableFilamentServiceProvider;
use Liberu\Accounting\AccountsPayableFilament\Resources\Pages\CreatePayable;
use Liberu\Accounting\AccountsPayableFilament\Resources\Pages\ListPayables;
use Liberu\Accounting\AccountsPayableFilament\Resources\PayableOpenItemResource;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\FinancialMasterData\Models\Party;

it('registers and configures accounting-accounts-payable-filament filament resources', function (): void {
    $provider = $this->app->register(AccountsPayableFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    $plugin = AccountsPayableFilamentPlugin::make();
    $panel = Panel::make()->id('test-ap');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();

    $host = new ListPayables();
    expect(PayableOpenItemResource::getPages())->toBeArray();
    expect(PayableOpenItemResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(PayableOpenItemResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(PayableOpenItemResource::table(Table::make($host)))->toBeInstanceOf(Table::class);

    $entity = LegalEntity::query()->create(['name' => 'AP Ent', 'currency_code' => 'GBP', 'accounting_basis' => 'accrual']);
    $party = Party::query()->create(['legal_entity_id' => $entity->getKey(), 'type' => 'supplier', 'name' => 'Sup 1']);
    $page = new CreatePayable();
    $record = Closure::bind(fn () => $this->handleRecordCreation([
        'party_id' => $party->getKey(),
        'reference' => 'AP-001',
        'currency' => 'GBP',
        'original_amount' => 100,
        'issued_on' => '2026-01-01',
        'due_on' => '2026-02-01',
    ]), $page, $page::class)();
    expect($record)->not->toBeNull();
});
