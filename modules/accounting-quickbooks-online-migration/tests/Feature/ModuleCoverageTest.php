<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\QuickBooksOnlineMigration\Actions\CreateMigrationRun;
use Liberu\Accounting\QuickBooksOnlineMigration\Actions\ImportMigrationRecords;
use Liberu\Accounting\QuickBooksOnlineMigration\Actions\ReconcileMigration;
use Liberu\Accounting\QuickBooksOnlineMigration\Enums\MigrationStatus;
use Liberu\Accounting\QuickBooksOnlineMigration\Enums\RecordStatus;
use Liberu\Accounting\QuickBooksOnlineMigration\Exceptions\InvalidMigration;
use Liberu\Accounting\QuickBooksOnlineMigration\Models\QboMigrationConnection;
use Liberu\Accounting\QuickBooksOnlineMigration\Models\QboMigrationRecord;
use Liberu\Accounting\QuickBooksOnlineMigration\Models\QboMigrationRun;
use Liberu\Accounting\QuickBooksOnlineMigration\QuickBooksOnlineMigrationServiceProvider;

it('registers the qbo migration service provider and runs migrations', function (): void {
    expect(app()->getProvider(QuickBooksOnlineMigrationServiceProvider::class))
        ->toBeInstanceOf(QuickBooksOnlineMigrationServiceProvider::class);

    expect(Schema::hasTable('accounting_qbo_migration_connections'))->toBeTrue()
        ->and(Schema::hasTable('accounting_qbo_migration_runs'))->toBeTrue()
        ->and(Schema::hasTable('accounting_qbo_migration_records'))->toBeTrue();

    expect(Schema::hasColumns('accounting_qbo_migration_connections', [
        'id', 'team_id', 'realm_id', 'access_token', 'refresh_token', 'token_expires_at', 'status', 'last_synced_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('accounting_qbo_migration_runs', [
        'id', 'connection_id', 'status', 'total_records', 'imported_records', 'failed_records', 'errors', 'metadata', 'started_at', 'finished_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('accounting_qbo_migration_records', [
        'id', 'run_id', 'entity_type', 'source_id', 'status', 'payload', 'payload_hash', 'error_message', 'metadata',
    ]))->toBeTrue();
});

it('exposes all qbo migration enum cases', function (): void {
    expect(array_column(MigrationStatus::cases(), 'value'))
        ->toBe(['draft', 'running', 'completed', 'failed', 'reconciled']);

    expect(array_column(RecordStatus::cases(), 'value'))
        ->toBe(['pending', 'imported', 'failed', 'skipped']);
});

it('creates connections with encrypted hidden tokens and casts', function (): void {
    $connection = QboMigrationConnection::create([
        'team_id' => 11,
        'realm_id' => 'realm-9901',
        'access_token' => 'secret-access-token',
        'refresh_token' => 'secret-refresh-token',
        'token_expires_at' => '2026-12-01 10:00:00',
        'status' => 'active',
        'last_synced_at' => '2026-11-22 12:00:00',
    ]);

    expect($connection->access_token)->toBe('secret-access-token')
        ->and($connection->refresh_token)->toBe('secret-refresh-token')
        ->and($connection->token_expires_at)->not->toBeNull()
        ->and($connection->last_synced_at)->not->toBeNull();

    $serialized = $connection->toArray();
    expect($serialized)->not->toHaveKey('access_token')
        ->and($serialized)->not->toHaveKey('refresh_token');

    $rawAccess = DB::table('accounting_qbo_migration_connections')
        ->where('id', $connection->id)
        ->value('access_token');

    expect($rawAccess)->not->toBe('secret-access-token');
});

it('creates migration runs with and without a connection', function (): void {
    $connection = QboMigrationConnection::create([
        'team_id' => 12,
        'realm_id' => 'realm-1002',
        'status' => 'active',
    ]);

    $action = new CreateMigrationRun();

    $runWithConnection = $action->handle($connection->id, ['source' => 'qbo-sandbox']);
    expect($runWithConnection)->toBeInstanceOf(QboMigrationRun::class)
        ->and($runWithConnection->connection_id)->toBe($connection->id)
        ->and($runWithConnection->status)->toBe(MigrationStatus::Draft)
        ->and($runWithConnection->metadata)->toBe(['source' => 'qbo-sandbox']);

    $standaloneRun = $action->handle(null);
    expect($standaloneRun->connection_id)->toBeNull()
        ->and($standaloneRun->status)->toBe(MigrationStatus::Draft)
        ->and($standaloneRun->metadata)->toBe([]);
});

it('imports migration records idempotently and validates input records', function (): void {
    $run = (new CreateMigrationRun())->handle(null, ['batch' => 1]);
    $importer = new ImportMigrationRecords();

    $payload1 = ['Name' => 'Cash', 'AccountType' => 'Bank'];
    $payload2 = ['Name' => 'Acme Corp', 'Active' => true];

    $completedRun = $importer->handle($run, [
        [
            'entity_type' => 'Account',
            'source_id' => 101,
            'payload' => $payload1,
            'metadata' => ['line' => 1],
        ],
        [
            'entity_type' => 'Customer',
            'source_id' => 'cust-2',
            'payload' => $payload2,
        ],
    ]);

    expect($completedRun->status)->toBe(MigrationStatus::Completed)
        ->and($completedRun->total_records)->toBe(2)
        ->and($completedRun->imported_records)->toBe(2)
        ->and($completedRun->started_at)->not->toBeNull()
        ->and($completedRun->finished_at)->not->toBeNull()
        ->and($completedRun->records)->toHaveCount(2);

    $firstRecord = $completedRun->records->firstWhere('entity_type', 'Account');
    expect($firstRecord)->toBeInstanceOf(QboMigrationRecord::class)
        ->and($firstRecord->source_id)->toBe('101')
        ->and($firstRecord->status)->toBe(RecordStatus::Imported)
        ->and($firstRecord->payload)->toBe($payload1)
        ->and($firstRecord->payload_hash)->toBe(hash('sha256', json_encode($payload1, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))
        ->and($firstRecord->metadata)->toBe(['line' => 1]);

    // Re-importing the same entity_type and source_id updates in place
    $updatedPayload = ['Name' => 'Operating Cash', 'AccountType' => 'Bank'];
    $importer->handle($completedRun, [
        [
            'entity_type' => 'Account',
            'source_id' => '101',
            'payload' => $updatedPayload,
        ],
    ]);

    expect($completedRun->records()->count())->toBe(2)
        ->and($completedRun->records()->where('entity_type', 'Account')->first()?->payload)->toBe($updatedPayload);

    // Empty record batch completes with zero counts
    $emptyRun = (new CreateMigrationRun())->handle(null);
    $emptyResult = $importer->handle($emptyRun, []);
    expect($emptyResult->status)->toBe(MigrationStatus::Completed)
        ->and($emptyResult->total_records)->toBe(0)
        ->and($emptyResult->imported_records)->toBe(0);

    // Invalid record branches
    foreach ([
        'not-an-array',
        ['entity_type' => '', 'source_id' => '1', 'payload' => []],
        ['entity_type' => 'Invoice', 'source_id' => '', 'payload' => []],
        ['entity_type' => 'Invoice', 'source_id' => '9', 'payload' => 'invalid'],
    ] as $badRecord) {
        expect(fn () => $importer->handle($emptyRun, [$badRecord]))
            ->toThrow(InvalidMigration::class, 'Each record requires entity_type, source_id, and payload.');
    }
});

it('reconciles migration runs as reconciled or failed based on failed records', function (): void {
    $create = new CreateMigrationRun();
    $importer = new ImportMigrationRecords();
    $reconciler = new ReconcileMigration();

    $cleanRun = $create->handle(null, ['phase' => 'initial']);
    $importer->handle($cleanRun, [
        ['entity_type' => 'Item', 'source_id' => '1', 'payload' => ['name' => 'Widget']],
    ]);

    $reconciled = $reconciler->handle($cleanRun);
    expect($reconciled->status)->toBe(MigrationStatus::Reconciled)
        ->and($reconciled->failed_records)->toBe(0)
        ->and($reconciled->metadata)->toHaveKey('phase', 'initial')
        ->and($reconciled->metadata)->toHaveKey('reconciled_at');

    $failingRun = $create->handle(null);
    QboMigrationRecord::create([
        'run_id' => $failingRun->id,
        'entity_type' => 'Invoice',
        'source_id' => 'INV-404',
        'status' => RecordStatus::Failed,
        'payload' => ['id' => 'INV-404'],
        'error_message' => 'Missing customer mapping.',
    ]);

    $failed = $reconciler->handle($failingRun);
    expect($failed->status)->toBe(MigrationStatus::Failed)
        ->and($failed->failed_records)->toBe(1)
        ->and($failed->metadata)->toHaveKey('reconciled_at');
});
