<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\BankAccounts\Models\BankAccount;
use Liberu\Accounting\BankFeeds\Actions\CreateBankFeedConnection;
use Liberu\Accounting\BankFeeds\Actions\ImportBankFeedTransactions;
use Liberu\Accounting\BankFeeds\Actions\MapBankFeedAccount;
use Liberu\Accounting\BankFeeds\Actions\SetBankFeedTransactionStatus;
use Liberu\Accounting\BankFeeds\Actions\UpdateBankFeedHealth;
use Liberu\Accounting\BankFeeds\Actions\UpsertBankFeedInstitution;
use Liberu\Accounting\BankFeeds\BankFeedsServiceProvider;
use Liberu\Accounting\BankFeeds\Contracts\BankFeedAdapter;
use Liberu\Accounting\BankFeeds\Enums\ConnectionStatus;
use Liberu\Accounting\BankFeeds\Enums\FeedTransactionStatus;
use Liberu\Accounting\BankFeeds\Events\BankFeedConnectionCreated;
use Liberu\Accounting\BankFeeds\Events\BankFeedImported;
use Liberu\Accounting\BankFeeds\Exceptions\InvalidBankFeed;
use Liberu\Accounting\BankFeeds\Models\BankFeedAccountMapping;
use Liberu\Accounting\BankFeeds\Models\BankFeedConnection;
use Liberu\Accounting\BankFeeds\Models\BankFeedInstitution;
use Liberu\Accounting\BankFeeds\Models\BankFeedTransaction;
use Liberu\Accounting\BankFeeds\Queries\BankFeedQuery;
use Liberu\Accounting\Core\Models\LegalEntity;

it('boots bank feeds service provider and covers enums, events, exceptions, and contract', function (): void {
    $provider = $this->app->register(BankFeedsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(BankFeedsServiceProvider::class)
        ->and(array_column(ConnectionStatus::cases(), 'value'))->toBe(['active', 'paused', 'error', 'revoked'])
        ->and(array_column(FeedTransactionStatus::cases(), 'value'))->toBe(['pending', 'posted', 'removed'])
        ->and((new InvalidBankFeed('Feed error'))->getMessage())->toBe('Feed error');

    $adapter = new class() implements BankFeedAdapter
    {
        public function fetch(BankFeedConnection $connection): array
        {
            return [
                'added' => [['external_id' => 'tx-1', 'amount' => 10.0, 'currency' => 'USD', 'transaction_date' => '2026-01-10']],
                'modified' => [],
                'removed' => [],
                'next_cursor' => 'cur-1',
            ];
        }
    };

    $connection = new BankFeedConnection(['provider' => 'plaid']);
    $fetched = $adapter->fetch($connection);
    expect($fetched['next_cursor'])->toBe('cur-1')
        ->and($fetched['added'])->toHaveCount(1);
});

it('manages institutions, connections, mappings, transaction imports, health, and queries', function (): void {
    Event::fake([BankFeedConnectionCreated::class, BankFeedImported::class]);

    $query = app(BankFeedQuery::class);
    expect($query->connections()->get())->toHaveCount(0);

    $upsertInstitution = app(UpsertBankFeedInstitution::class);
    $institution = $upsertInstitution->handle([
        'provider' => 'plaid',
        'external_id' => 'ins_1',
        'name' => 'First Bank',
        'country' => 'US',
        'metadata' => ['oauth' => true],
    ]);

    $updatedInstitution = $upsertInstitution->handle([
        'provider' => 'plaid',
        'external_id' => 'ins_1',
        'name' => 'First National Bank',
    ]);

    expect($updatedInstitution->getKey())->toBe($institution->getKey())
        ->and($updatedInstitution->name)->toBe('First National Bank');

    $otherInstitution = $upsertInstitution->handle([
        'provider' => 'revolut',
        'external_id' => 'rev_1',
        'name' => 'Revolut Business',
    ]);

    $createConnection = app(CreateBankFeedConnection::class);
    $connection = $createConnection->handle([
        'team_id' => 10,
        'user_id' => 5,
        'institution_id' => $institution->getKey(),
        'provider' => 'plaid',
        'name' => 'Primary Feed',
        'external_reference' => 'conn_1',
        'access_token' => 'secret-token',
        'credentials' => ['client' => 'abc'],
        'metadata' => ['env' => 'sandbox'],
    ]);

    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->access_token)->toBe('secret-token')
        ->and($connection->credentials)->toBe(['client' => 'abc'])
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'credentials', 'cursor'])
        ->and($connection->institution->is($institution))->toBeTrue()
        ->and($institution->connections()->count())->toBe(1);

    Event::assertDispatched(BankFeedConnectionCreated::class, fn (BankFeedConnectionCreated $e): bool => $e->connection->is($connection));

    $secondConnection = $createConnection->handle([
        'team_id' => 20,
        'institution_id' => $otherInstitution->getKey(),
        'provider' => 'revolut',
        'name' => 'Team 20 Feed',
        'external_reference' => 'conn_2',
        'access_token' => 'rev-token',
    ]);

    $entity = LegalEntity::query()->create([
        'name' => 'Feed Entity',
        'currency_code' => 'USD',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
    $bankAccount = BankAccount::query()->create([
        'legal_entity_id' => $entity->getKey(),
        'name' => 'Checking',
        'account_type' => 'bank',
        'currency' => 'USD',
        'opening_balance' => 0,
        'opening_date' => '2026-01-01',
    ]);

    $mapping = app(MapBankFeedAccount::class)->handle($connection, [
        'bank_account_id' => $bankAccount->getKey(),
        'external_account_id' => 'ext_acc_1',
        'name' => 'External Checking',
        'currency' => 'USD',
        'metadata' => ['mask' => '1234'],
    ]);

    expect($mapping->connection->is($connection))->toBeTrue()
        ->and($connection->mappings()->count())->toBe(1);

    $importer = app(ImportBankFeedTransactions::class);
    $firstBatch = $importer->handle($connection, [
        'added' => [
            [
                'external_id' => 'tx_1',
                'mapping_id' => $mapping->getKey(),
                'transaction_date' => '2026-01-10',
                'posted_date' => '2026-01-11',
                'description' => 'Coffee Shop',
                'amount' => -12.50,
                'currency' => 'usd',
                'status' => 'pending',
                'category' => 'meals',
            ],
            [
                'transaction_id' => 'tx_2',
                'transaction_date' => '2026-01-12',
                'description' => 'Client Wire',
                'amount' => 500.00,
                'currency' => 'USD',
            ],
        ],
        'next_cursor' => 'cursor_1',
    ]);

    expect($firstBatch)->toBe(['imported' => 2, 'duplicates' => 0]);

    $secondBatch = $importer->handle($connection->refresh(), [
        'modified' => [
            [
                'external_id' => 'tx_1',
                'transaction_date' => '2026-01-10',
                'description' => 'Coffee Shop Updated',
                'amount' => -12.50,
                'currency' => 'USD',
                'status' => 'posted',
            ],
        ],
        'removed' => ['tx_2'],
        'next_cursor' => 'cursor_2',
    ]);

    expect($secondBatch)->toBe(['imported' => 1, 'duplicates' => 1]);

    Event::assertDispatched(BankFeedImported::class, fn (BankFeedImported $e): bool => $e->imported === 1 && $e->duplicates === 1);

    $tx1 = BankFeedTransaction::query()->where('external_id', 'tx_1')->firstOrFail();
    $tx2 = BankFeedTransaction::query()->where('external_id', 'tx_2')->firstOrFail();

    expect($tx1->status)->toBe(FeedTransactionStatus::Posted)
        ->and($tx1->currency)->toBe('USD')
        ->and($tx1->connection->is($connection))->toBeTrue()
        ->and($tx1->mapping->is($mapping))->toBeTrue()
        ->and($tx1->toArray())->not->toHaveKey('raw_data')
        ->and($tx2->status)->toBe(FeedTransactionStatus::Removed)
        ->and($connection->transactions()->count())->toBe(2)
        ->and($connection->refresh()->cursor)->toBe('cursor_2');

    $setTxStatus = app(SetBankFeedTransactionStatus::class);
    expect($setTxStatus->handle($tx1, FeedTransactionStatus::Pending)->status)->toBe(FeedTransactionStatus::Pending)
        ->and($setTxStatus->handle($tx2, FeedTransactionStatus::Removed)->status)->toBe(FeedTransactionStatus::Removed);

    $health = app(UpdateBankFeedHealth::class);
    $errored = $health->handle($connection, 'Token expired');
    expect($errored->status)->toBe(ConnectionStatus::Error)
        ->and($errored->last_error)->toBe('Token expired')
        ->and($errored->last_error_at)->not->toBeNull();

    $recovered = $health->handle($errored, null);
    expect($recovered->status)->toBe(ConnectionStatus::Active)
        ->and($recovered->last_error)->toBeNull()
        ->and($recovered->last_error_at)->toBeNull();

    expect($query->connections()->count())->toBe(2)
        ->and($query->connections(10)->pluck('id')->all())->toBe([$connection->getKey()])
        ->and($query->connections(20)->pluck('id')->all())->toBe([$secondConnection->getKey()]);
});

it('throws InvalidBankFeed on invalid inputs across bank feeds actions', function (): void {
    $upsert = app(UpsertBankFeedInstitution::class);
    expect(fn () => $upsert->handle(['provider' => '', 'external_id' => '1', 'name' => 'Bank']))->toThrow(InvalidBankFeed::class)
        ->and(fn () => $upsert->handle(['provider' => 'plaid', 'external_id' => '', 'name' => 'Bank']))->toThrow(InvalidBankFeed::class)
        ->and(fn () => $upsert->handle(['provider' => 'plaid', 'external_id' => '1', 'name' => '']))->toThrow(InvalidBankFeed::class);

    $instA = $upsert->handle(['provider' => 'plaid', 'external_id' => 'ins_a', 'name' => 'Bank A']);
    $instB = $upsert->handle(['provider' => 'wise', 'external_id' => 'ins_b', 'name' => 'Bank B']);

    $create = app(CreateBankFeedConnection::class);
    expect(fn () => $create->handle(['provider' => '', 'external_reference' => 'r1', 'institution_id' => $instA->getKey(), 'access_token' => 'tok']))->toThrow(InvalidBankFeed::class)
        ->and(fn () => $create->handle(['provider' => 'plaid', 'external_reference' => '', 'institution_id' => $instA->getKey(), 'access_token' => 'tok']))->toThrow(InvalidBankFeed::class)
        ->and(fn () => $create->handle(['provider' => 'plaid', 'external_reference' => 'r1', 'institution_id' => 999999, 'access_token' => 'tok']))->toThrow(InvalidBankFeed::class)
        ->and(fn () => $create->handle(['provider' => 'plaid', 'external_reference' => 'r1', 'institution_id' => $instB->getKey(), 'access_token' => 'tok']))->toThrow(InvalidBankFeed::class)
        ->and(fn () => $create->handle(['provider' => 'plaid', 'external_reference' => 'r1', 'institution_id' => $instA->getKey(), 'access_token' => '']))->toThrow(InvalidBankFeed::class);

    $conn = $create->handle([
        'institution_id' => $instA->getKey(),
        'provider' => 'plaid',
        'name' => 'Conn A',
        'external_reference' => 'ref_a',
        'access_token' => 'tok',
    ]);

    expect(fn () => $create->handle([
        'institution_id' => $instA->getKey(),
        'provider' => 'plaid',
        'name' => 'Conn Dup',
        'external_reference' => 'ref_a',
        'access_token' => 'tok',
    ]))->toThrow(InvalidBankFeed::class, 'already exists');

    $map = app(MapBankFeedAccount::class);
    expect(fn () => $map->handle($conn, ['bank_account_id' => 999999, 'external_account_id' => 'ext']))->toThrow(InvalidBankFeed::class)
        ->and(fn () => $map->handle($conn, ['bank_account_id' => null, 'external_account_id' => '']))->toThrow(InvalidBankFeed::class);

    $import = app(ImportBankFeedTransactions::class);
    expect(fn () => $import->handle($conn, ['added' => [['external_id' => '', 'amount' => 10, 'currency' => 'USD', 'transaction_date' => '2026-01-01']]]))->toThrow(InvalidBankFeed::class)
        ->and(fn () => $import->handle($conn, ['added' => [['external_id' => 't1', 'amount' => null, 'currency' => 'USD', 'transaction_date' => '2026-01-01']]]))->toThrow(InvalidBankFeed::class)
        ->and(fn () => $import->handle($conn, ['added' => [['external_id' => 't1', 'amount' => 10, 'currency' => '', 'transaction_date' => '2026-01-01']]]))->toThrow(InvalidBankFeed::class)
        ->and(fn () => $import->handle($conn, ['added' => [['external_id' => 't1', 'amount' => 10, 'currency' => 'USD', 'transaction_date' => '']]]))->toThrow(InvalidBankFeed::class);

    $tx = BankFeedTransaction::query()->create([
        'connection_id' => $conn->getKey(),
        'external_id' => 'tx_removed',
        'transaction_date' => '2026-01-01',
        'amount' => 10,
        'currency' => 'USD',
        'status' => FeedTransactionStatus::Removed,
    ]);

    expect(fn () => app(SetBankFeedTransactionStatus::class)->handle($tx, FeedTransactionStatus::Posted))
        ->toThrow(InvalidBankFeed::class, 'cannot be restored');
});
