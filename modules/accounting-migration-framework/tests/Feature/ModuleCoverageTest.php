<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\MigrationFramework\Actions\CreateBatch;
use Liberu\Accounting\MigrationFramework\Actions\CreateMapping;
use Liberu\Accounting\MigrationFramework\Actions\ReconcileBatch;
use Liberu\Accounting\MigrationFramework\Actions\RegisterSource;
use Liberu\Accounting\MigrationFramework\Actions\RunBatch;
use Liberu\Accounting\MigrationFramework\Enums\MigrationStatus;
use Liberu\Accounting\MigrationFramework\Enums\RowStatus;
use Liberu\Accounting\MigrationFramework\Events\MigrationBatchCompleted;
use Liberu\Accounting\MigrationFramework\Exceptions\InvalidMigration;
use Liberu\Accounting\MigrationFramework\MigrationFrameworkServiceProvider;
use Liberu\Accounting\MigrationFramework\Models\MigrationAttachment;
use Liberu\Accounting\MigrationFramework\Models\MigrationBatch;
use Liberu\Accounting\MigrationFramework\Models\MigrationMapping;
use Liberu\Accounting\MigrationFramework\Models\MigrationReconciliation;
use Liberu\Accounting\MigrationFramework\Models\MigrationRow;
use Liberu\Accounting\MigrationFramework\Models\MigrationSource;
use Liberu\Accounting\MigrationFramework\Queries\MigrationQuery;

it('registers the service provider, singleton query, and migrations', function (): void {
    expect(app()->getProviders(MigrationFrameworkServiceProvider::class))->not->toBeEmpty()
        ->and(app(MigrationQuery::class))->toBe(app(MigrationQuery::class))
        ->and(Schema::hasTable('accounting_migration_sources'))->toBeTrue()
        ->and(Schema::hasTable('accounting_migration_mappings'))->toBeTrue()
        ->and(Schema::hasTable('accounting_migration_batches'))->toBeTrue()
        ->and(Schema::hasTable('accounting_migration_rows'))->toBeTrue()
        ->and(Schema::hasTable('accounting_migration_attachments'))->toBeTrue()
        ->and(Schema::hasTable('accounting_migration_reconciliations'))->toBeTrue();
});

it('exposes migration enums and exception', function (): void {
    expect(array_map(fn (MigrationStatus $s) => $s->value, MigrationStatus::cases()))
        ->toBe(['draft', 'dry_run', 'running', 'paused', 'completed', 'failed', 'reconciled'])
        ->and(array_map(fn (RowStatus $s) => $s->value, RowStatus::cases()))
        ->toBe(['pending', 'valid', 'imported', 'skipped', 'failed']);

    $exception = new InvalidMigration('Bad migration');
    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe('Bad migration');
});

it('registers source, creates mapping, runs dry-run then live batch with transforms, and reconciles', function (): void {
    $registerSource = new RegisterSource();
    $createMapping = new CreateMapping();
    $createBatch = new CreateBatch();
    $runBatch = new RunBatch();
    $reconcileBatch = new ReconcileBatch();

    $source = $registerSource->handle([
        'team_id' => 4,
        'source_ref' => 'XERO-ORG-1',
        'provider' => 'xero',
        'source_type' => 'api',
        'name' => 'Xero Org',
        'record_count' => 2,
    ]);
    expect($source)->toBeInstanceOf(MigrationSource::class)
        ->and($source->record_count)->toBe(2);

    $mapping = $createMapping->handle($source, [
        'mapping_ref' => 'MAP-ACCOUNTS',
        'entity_type' => 'account',
        'field_map' => ['code' => 'account_code', 'name' => 'account_name'],
        'transforms' => [
            'code' => ['operation' => 'uppercase'],
            'name' => ['operation' => 'trim'],
        ],
    ]);
    expect($mapping)->toBeInstanceOf(MigrationMapping::class)
        ->and($mapping->source->is($source))->toBeTrue()
        ->and($source->mappings()->count())->toBe(1);

    $rows = [
        ['row_ref' => 'R1', 'source_key' => 'K1', 'payload' => ['code' => 'rev1', 'name' => '  Sales  ']],
        ['source_key' => 'K2', 'payload' => ['code' => 'exp1', 'name' => '  Rent  ']],
    ];

    $batch = $createBatch->handle($source, $mapping, [
        'team_id' => 4,
        'batch_ref' => 'BATCH-001',
    ], $rows);

    expect($batch)->toBeInstanceOf(MigrationBatch::class)
        ->and($batch->status)->toBe(MigrationStatus::Draft)
        ->and($batch->total_count)->toBe(2)
        ->and(strlen((string) $batch->resume_token))->toBe(48)
        ->and($batch->source->is($source))->toBeTrue()
        ->and($batch->mapping->is($mapping))->toBeTrue()
        ->and($source->batches()->count())->toBe(1)
        ->and($batch->rows)->toHaveCount(2)
        ->and($batch->rows->first())->toBeInstanceOf(MigrationRow::class)
        ->and($batch->rows->first()->batch->is($batch))->toBeTrue();

    // Idempotent CreateBatch with identical rows
    $sameBatch = $createBatch->handle($source, $mapping, [
        'team_id' => 4,
        'batch_ref' => 'BATCH-001',
    ], $rows);
    expect($sameBatch->id)->toBe($batch->id);

    // Attachment relationship
    $attachment = $batch->attachments()->create([
        'file_ref' => 'file-1',
        'name' => 'export.csv',
        'mime_type' => 'text/csv',
        'size_bytes' => 1024,
    ]);
    expect($attachment)->toBeInstanceOf(MigrationAttachment::class)
        ->and($attachment->batch->is($batch))->toBeTrue();

    // Dry run
    $dryBatch = $runBatch->handle($batch, dryRun: true, limit: 10);
    expect($dryBatch->status)->toBe(MigrationStatus::DryRun)
        ->and($dryBatch->processed_count)->toBe(2)
        ->and($dryBatch->success_count)->toBe(2)
        ->and($dryBatch->rows()->first()->status)->toBe(RowStatus::Valid)
        ->and($dryBatch->rows()->first()->transformed_payload)->toBe(['code' => 'REV1', 'name' => 'Sales']);

    // Partial live run (resets Valid rows back to Pending, processes 1 row)
    $partial = $runBatch->handle($dryBatch, dryRun: false, limit: 1);
    expect($partial->status)->toBe(MigrationStatus::Running)
        ->and($partial->processed_count)->toBe(1)
        ->and($partial->success_count)->toBe(1);

    // Complete live run (processes remaining 1 row)
    $completed = $runBatch->handle($partial, dryRun: false, limit: 10);
    expect($completed->status)->toBe(MigrationStatus::Completed)
        ->and($completed->processed_count)->toBe(2)
        ->and($completed->completed_at)->not->toBeNull()
        ->and($completed->rows()->where('status', RowStatus::Imported)->count())->toBe(2);

    // Reconcile matched
    $recon = $reconcileBatch->handle($completed, [
        'source_total' => 500.00,
        'destination_total' => 500.00,
        'notes' => 'Balanced',
    ]);
    $completed->refresh();

    expect($recon)->toBeInstanceOf(MigrationReconciliation::class)
        ->and($recon->status)->toBe('matched')
        ->and((float) $recon->variance)->toBe(0.0)
        ->and($recon->batch->is($completed))->toBeTrue()
        ->and($completed->reconciliation()->count())->toBe(1)
        ->and($completed->status)->toBe(MigrationStatus::Reconciled);

    // Running an already-reconciled batch returns immediately
    expect($runBatch->handle($completed)->id)->toBe($completed->id);

    // Event test
    Event::fake([MigrationBatchCompleted::class]);
    MigrationBatchCompleted::dispatch($completed);
    Event::assertDispatched(MigrationBatchCompleted::class, fn (MigrationBatchCompleted $e) => $e->batch->is($completed));
});

it('handles failed rows, variance reconciliation, and all validation exceptions', function (): void {
    $registerSource = new RegisterSource();
    $createMapping = new CreateMapping();
    $createBatch = new CreateBatch();
    $runBatch = new RunBatch();
    $reconcileBatch = new ReconcileBatch();

    expect(fn () => $registerSource->handle(['source_ref' => '', 'provider' => 'x', 'source_type' => 'csv', 'name' => 'N']))
        ->toThrow(InvalidMigration::class);

    $s1 = $registerSource->handle(['team_id' => 9, 'source_ref' => 'SRC-1', 'provider' => 'csv', 'source_type' => 'file', 'name' => 'CSV 1']);
    $s2 = $registerSource->handle(['team_id' => 9, 'source_ref' => 'SRC-2', 'provider' => 'csv', 'source_type' => 'file', 'name' => 'CSV 2']);

    expect(fn () => $createMapping->handle($s1, ['mapping_ref' => 'M1', 'entity_type' => 'invoice', 'field_map' => []]))
        ->toThrow(InvalidMigration::class);

    $m2 = $createMapping->handle($s2, ['mapping_ref' => 'M2', 'entity_type' => 'invoice', 'field_map' => ['a' => 'b']]);

    // Mapping from wrong source
    expect(fn () => $createBatch->handle($s1, $m2, ['batch_ref' => 'B-BAD']))
        ->toThrow(InvalidMigration::class, 'A batch requires a reference and a mapping belonging to its source.');

    $m1 = $createMapping->handle($s1, ['mapping_ref' => 'M1', 'entity_type' => 'invoice', 'field_map' => ['a' => 'b']]);

    // Row missing source_key
    expect(fn () => $createBatch->handle($s1, $m1, ['team_id' => 9, 'batch_ref' => 'B-NO-KEY'], [['payload' => ['a' => 1]]]))
        ->toThrow(InvalidMigration::class, 'Every migration row requires a source key.');

    // Batch with an empty-payload row to trigger failure path
    $batch = $createBatch->handle($s1, $m1, ['team_id' => 9, 'batch_ref' => 'B-FAIL'], [
        ['source_key' => 'EMPTY-1', 'payload' => []],
    ]);

    // Different rows hash with same batch_ref
    expect(fn () => $createBatch->handle($s1, $m1, ['team_id' => 9, 'batch_ref' => 'B-FAIL'], [
        ['source_key' => 'OTHER-1', 'payload' => ['x' => 1]],
    ]))->toThrow(InvalidMigration::class, 'Batch reference already exists with different rows.');

    // Limit < 1
    expect(fn () => $runBatch->handle($batch, false, 0))
        ->toThrow(InvalidMigration::class, 'Batch limit must be positive.');

    // Cannot reconcile draft batch
    expect(fn () => $reconcileBatch->handle($batch))
        ->toThrow(InvalidMigration::class, 'Only completed batches can be reconciled.');

    $completed = $runBatch->handle($batch);
    expect($completed->status)->toBe(MigrationStatus::Completed)
        ->and($completed->error_count)->toBe(1)
        ->and($completed->rows()->first()->status)->toBe(RowStatus::Failed)
        ->and($completed->rows()->first()->error_code)->toBe('invalid_payload');

    $varianceRecon = $reconcileBatch->handle($completed, [
        'source_total' => 100.00,
        'destination_total' => 90.00,
    ]);
    expect($varianceRecon->status)->toBe('variance')
        ->and((float) $varianceRecon->variance)->toBe(-10.0)
        ->and($completed->refresh()->status)->toBe(MigrationStatus::Completed);
});

it('queries migration sources, batches, and batch counts', function (): void {
    $query = app(MigrationQuery::class);
    expect($query->sources()->total())->toBe(0)
        ->and($query->batches()->total())->toBe(0);

    $source = (new RegisterSource())->handle([
        'team_id' => 20,
        'source_ref' => 'SRC-Q',
        'provider' => 'quickbooks',
        'source_type' => 'api',
        'name' => 'QB',
    ]);
    $mapping = (new CreateMapping())->handle($source, [
        'mapping_ref' => 'MAP-Q',
        'entity_type' => 'bill',
        'field_map' => ['id' => 'ref'],
    ]);
    $batch = (new CreateBatch())->handle($source, $mapping, [
        'team_id' => 20,
        'batch_ref' => 'BATCH-Q',
    ], [
        ['source_key' => '1', 'payload' => ['id' => 1]],
        ['source_key' => '2', 'payload' => ['id' => 2]],
    ]);

    expect($query->sources(teamId: 20)->total())->toBe(1)
        ->and($query->sources(teamId: 999)->total())->toBe(0)
        ->and($query->sources(perPage: 0)->perPage())->toBe(1)
        ->and($query->batches(teamId: 20)->total())->toBe(1)
        ->and($query->batches(perPage: 500)->perPage())->toBe(100)
        ->and($query->counts($batch))->toBe([
            'total' => 2,
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'skipped' => 0,
            'remaining' => 2,
        ]);
});
