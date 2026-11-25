<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\BankAccounts\Enums\BankAccountType;
use Liberu\Accounting\BankAccountsFilament\BankAccountsFilamentPlugin;
use Liberu\Accounting\BankAccountsFilament\BankAccountsFilamentServiceProvider;
use Liberu\Accounting\BankAccountsFilament\Resources\BankAccountResource;
use Liberu\Accounting\BankAccountsFilament\Resources\BankAccountResource\Pages\CreateBankAccount;
use Liberu\Accounting\BankAccountsFilament\Resources\BankAccountResource\Pages\ListBankAccounts;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;

it('registers and configures accounting-bank-accounts-filament filament resources', function (): void {
    $provider = $this->app->register(BankAccountsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    $plugin = BankAccountsFilamentPlugin::make();
    $panel = Panel::make()->id('test-ba');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();

    $host = new ListBankAccounts();
    expect(BankAccountResource::getPages())->toBeArray();
    expect(BankAccountResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(BankAccountResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(BankAccountResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    expect(Closure::bind(fn () => $this->getHeaderActions(), $host, $host::class)())->toBeArray();

    $this->actingAs(User::factory()->create(['current_team_id' => 1]));
    $entity = LegalEntity::query()->create(['name' => 'BA Ent', 'currency_code' => 'GBP', 'accounting_basis' => 'accrual']);
    $page = new CreateBankAccount();
    $record = Closure::bind(fn () => $this->handleRecordCreation([
        'legal_entity_id' => $entity->getKey(),
        'name' => 'Main Checking',
        'account_type' => BankAccountType::cases()[0]->value,
        'currency' => 'GBP',
        'opening_date' => '2026-01-01',
        'opening_balance' => 0,
    ]), $page, $page::class)();
    expect($record)->not->toBeNull();
});
