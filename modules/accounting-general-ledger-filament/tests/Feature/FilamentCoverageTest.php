<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\GeneralLedgerFilament\GeneralLedgerFilamentPlugin;
use Liberu\Accounting\GeneralLedgerFilament\GeneralLedgerFilamentServiceProvider;
use Liberu\Accounting\GeneralLedgerFilament\Resources\JournalResource;
use Liberu\Accounting\GeneralLedgerFilament\Resources\JournalResource\Pages\CreateJournal;
use Liberu\Accounting\GeneralLedgerFilament\Resources\JournalResource\Pages\ListJournals;

it('registers and configures accounting-general-ledger-filament filament resources', function (): void {
    $provider = $this->app->register(GeneralLedgerFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    $plugin = GeneralLedgerFilamentPlugin::make();
    $panel = Panel::make()->id('test-gl');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();

    $host = new ListJournals();
    expect(JournalResource::getPages())->toBeArray();
    expect(JournalResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(JournalResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(JournalResource::table(Table::make($host)))->toBeInstanceOf(Table::class);

    $entity = LegalEntity::query()->create(['name' => 'GL Ent', 'currency_code' => 'USD', 'accounting_basis' => 'accrual']);
    $book = Book::query()->create(['legal_entity_id' => $entity->getKey(), 'name' => 'GL Book', 'code' => 'GLB', 'accounting_basis' => 'accrual', 'is_active' => true]);
    $cash = Account::query()->create(['legal_entity_id' => $entity->getKey(), 'code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit']);
    $rev = Account::query()->create(['legal_entity_id' => $entity->getKey(), 'code' => '4000', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit']);

    $page = new CreateJournal();
    $journal = Closure::bind(fn () => $this->handleRecordCreation([
        'book_id' => $book->getKey(),
        'entry_date' => '2026-01-15',
        'description' => 'Opening',
        'lines' => [
            ['account_id' => $cash->getKey(), 'debit' => 100, 'credit' => 0],
            ['account_id' => $rev->getKey(), 'debit' => 0, 'credit' => 100],
        ],
    ]), $page, $page::class)();
    expect($journal)->not->toBeNull();
});
