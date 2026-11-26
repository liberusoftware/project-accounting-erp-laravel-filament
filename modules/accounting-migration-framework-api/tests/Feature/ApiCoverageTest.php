<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\MigrationFrameworkApi\MigrationFrameworkApiServiceProvider;

it('covers accounting-migration-framework-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(MigrationFrameworkApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(MigrationFrameworkApiServiceProvider::class);

    $sourceResponse = $this->postJson('/api/v1/accounting/migration-framework/sources', [
        'team_id' => 1,
        'source_ref' => 'SRC-001',
        'provider' => 'csv',
        'source_type' => 'invoices',
        'name' => 'Legacy Invoices CSV',
    ]);
    $sourceResponse->assertCreated();
    $sourceId = $sourceResponse->json('data.id');

    $this->getJson('/api/v1/accounting/migration-framework/sources?team_id=1')->assertOk();

    $mappingResponse = $this->postJson("/api/v1/accounting/migration-framework/sources/{$sourceId}/mappings", [
        'mapping_ref' => 'MAP-001',
        'entity_type' => 'invoice',
        'field_map' => ['ref' => 'invoice_number'],
    ]);
    $mappingResponse->assertCreated();
    $mappingId = $mappingResponse->json('data.id');

    $batchResponse = $this->postJson("/api/v1/accounting/migration-framework/sources/{$sourceId}/batches", [
        'team_id' => 1,
        'batch_ref' => 'BATCH-001',
        'mapping_id' => $mappingId,
        'dry_run' => false,
        'rows' => [
            ['source_key' => 'ROW-1', 'payload' => ['ref' => 'INV-001']],
        ],
    ]);
    $batchResponse->assertSuccessful();
    $batchId = $batchResponse->json('data.id');

    $this->getJson('/api/v1/accounting/migration-framework/batches?team_id=1')->assertOk();
    $this->postJson("/api/v1/accounting/migration-framework/batches/{$batchId}/dry-run")->assertOk();
    $this->postJson("/api/v1/accounting/migration-framework/batches/{$batchId}/resume")->assertOk();
    $this->postJson("/api/v1/accounting/migration-framework/batches/{$batchId}/reconcile", [
        'source_total' => 100.00,
        'destination_total' => 100.00,
        'notes' => 'Reconciled cleanly',
    ])->assertOk();
    $this->getJson("/api/v1/accounting/migration-framework/batches/{$batchId}/counts")->assertOk();
});
