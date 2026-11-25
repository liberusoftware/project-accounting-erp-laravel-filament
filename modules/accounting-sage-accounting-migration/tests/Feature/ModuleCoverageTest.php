<?php

declare(strict_types=1);

use Liberu\Accounting\SageAccountingMigration\Actions\CreateMigrationRun;
use Liberu\Accounting\SageAccountingMigration\Actions\ImportMigrationRecords;
use Liberu\Accounting\SageAccountingMigration\Actions\ReconcileMigration;
use Liberu\Accounting\SageAccountingMigration\Enums\MigrationRecordStatus;
use Liberu\Accounting\SageAccountingMigration\Enums\MigrationRunStatus;
use Liberu\Accounting\SageAccountingMigration\Exceptions\InvalidMigration;
use Liberu\Accounting\SageAccountingMigration\Models\SageMigrationConnection;
use Liberu\Accounting\SageAccountingMigration\Models\SageMigrationRecord;
use Liberu\Accounting\SageAccountingMigration\Models\SageMigrationRun;
use Liberu\Accounting\SageAccountingMigration\SageAccountingMigrationServiceProvider;

it('boots sage accounting migration service provider and covers enums and exception', function (): void {
    $provider = $this->app->register(SageAccountingMigrationServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(SageAccountingMigrationServiceProvider::class)
        ->and(array_column(MigrationRecordStatus::cases(), 'value'))->toBe(['pending', 'imported', 'failed', 'skipped'])
        ->and(array_column(MigrationRunStatus::cases(), 'value'))->toBe(['draft', 'running', 'completed', 'failed', 'reconciled'])
        ->and((new InvalidMigration('Invalid migration'))->getMessage())->toBe('Invalid migration');
});

it('manages sage migration connections, runs, record imports, and successful reconciliation', function (): void {
    $connection = SageMigrationConnection::create([
        'team_id' => 10,
        'provider' => 'sage-accounting',
        'external_business_id' => 'sage-biz-001',
        'access_token' => 'secret-access-token',
        'refresh_token' => 'secret-refresh-token',
        'token_expires_at' => now()->addHour(),
        'status' => 'active',
        'last_synced_at' => now(),
    ]);

    expect($connection->access_token)->toBe('secret-access-token')
        ->and($connection->refresh_token)->toBe('secret-refresh-token')
        ->and($connection->token_expires_at)->not->toBeNull()
        ->and($connection->last_synced_at)->not->toBeNull()
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);

    $run = app(CreateMigrationRun::class)->handle($connection->id, ['source' => 'sage-uk']);

    expect($run)->toBeInstanceOf(SageMigrationRun::class)
        ->and($run->connection_id)->toBe($connection->id)
        ->and($run->status)->toBe(MigrationRunStatus::Draft)
        ->and($run->metadata)->toBe(['source' => 'sage-uk']);

    $importedRun = app(ImportMigrationRecords::class)->handle($run, [
        [
            'entity_type' => 'ledger_account',
            'source_id' => 'ACC-100',
            'payload' => ['code' => '1000', 'name' => 'Cash'],
            'metadata' => ['batch' => 1],
        ],
        [
            'entity_type' => 'contact',
            'source_id' => 200,
            'payload' => ['name' => 'Acme Ltd', 'url' => 'https://acme.example/profile'],
        ],
    ]);

    expect($importedRun->status)->toBe(MigrationRunStatus::Completed)
        ->and($importedRun->total_records)->toBe(2)
        ->and($importedRun->imported_records)->toBe(2)
        ->and($importedRun->started_at)->not->toBeNull()
        ->and($importedRun->finished_at)->not->toBeNull()
        ->and($importedRun->records()->count())->toBe(2);

    $firstRecord = $importedRun->records()->where('source_id', 'ACC-100')->firstOrFail();
    expect($firstRecord)->toBeInstanceOf(SageMigrationRecord::class)
        ->and($firstRecord->status)->toBe(MigrationRecordStatus::Imported)
        ->and($firstRecord->payload)->toBe(['code' => '1000', 'name' => 'Cash'])
        ->and($firstRecord->payload_hash)->toBe(hash('sha256', json_encode(['code' => '1000', 'name' => 'Cash'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))
        ->and($firstRecord->metadata)->toBe(['batch' => 1]);

    // Re-importing the same source_id updates the existing record idempotently
    $reimportedRun = app(ImportMigrationRecords::class)->handle($importedRun, [
        [
            'entity_type' => 'ledger_account',
            'source_id' => 'ACC-100',
            'payload' => ['code' => '1000', 'name' => 'Cash Updated'],
        ],
    ]);

    expect($reimportedRun->records()->count())->toBe(2)
        ->and($reimportedRun->records()->where('source_id', 'ACC-100')->firstOrFail()->payload)->toBe(['code' => '1000', 'name' => 'Cash Updated']);

    $reconciled = app(ReconcileMigration::class)->handle($reimportedRun);

    expect($reconciled->status)->toBe(MigrationRunStatus::Reconciled)
        ->and($reconciled->failed_records)->toBe(0)
        ->and($reconciled->metadata)->toHaveKey('reconciled_at')
        ->and($reconciled->metadata['source'])->toBe('sage-uk');
});

it('validates migration records and marks reconciliation failed when failed records exist', function (): void {
    $run = app(CreateMigrationRun::class)->handle(null);
    $importer = app(ImportMigrationRecords::class);

    expect(fn () => $importer->handle($run, ['not-an-array']))
        ->toThrow(InvalidMigration::class, 'Each migration record requires entity_type, source_id, and payload.')
        ->and(fn () => $importer->handle($run, [['source_id' => '1', 'payload' => []]]))
        ->toThrow(InvalidMigration::class, 'Each migration record requires entity_type, source_id, and payload.')
        ->and(fn () => $importer->handle($run, [['entity_type' => 'invoice', 'payload' => []]]))
        ->toThrow(InvalidMigration::class, 'Each migration record requires entity_type, source_id, and payload.')
        ->and(fn () => $importer->handle($run, [['entity_type' => 'invoice', 'source_id' => '1', 'payload' => 'invalid']]))
        ->toThrow(InvalidMigration::class, 'Each migration record requires entity_type, source_id, and payload.');

    // Clear metadata to exercise null metadata fallback during reconciliation
    $run->update(['metadata' => null]);

    SageMigrationRecord::create([
        'run_id' => $run->id,
        'entity_type' => 'invoice',
        'source_id' => 'INV-ERR-1',
        'status' => MigrationRecordStatus::Failed,
        'payload' => ['total' => 100],
        'error_message' => 'Missing tax code',
    ]);

    $reconciled = app(ReconcileMigration::class)->handle($run);

    expect($reconciled->status)->toBe(MigrationRunStatus::Failed)
        ->and($reconciled->failed_records)->toBe(1)
        ->and($reconciled->metadata)->toHaveKey('reconciled_at');
});
