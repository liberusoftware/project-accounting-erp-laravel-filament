<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\DocumentCaptureApi\DocumentCaptureApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-document-capture-api api provider, controllers, resources, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(DocumentCaptureApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DocumentCaptureApiServiceProvider::class);

    $doc1Store = $this->postJson('/api/v1/accounting/document-capture', [
        'team_id' => 1,
        'source_channel' => 'web',
        'file_ref' => 's3://bucket/receipt-001.pdf',
        'checksum' => 'sha256-receipt-001',
        'mime_type' => 'application/pdf',
        'retention_until' => '2030-12-31',
        'metadata' => ['uploader' => 'api'],
    ]);
    expect($doc1Store->getStatusCode())->toBeIn([200, 201]);
    $doc1Id = (string) $doc1Store->json('data.id');

    $doc2Store = $this->postJson('/api/v1/accounting/document-capture', [
        'team_id' => 1,
        'source_channel' => 'email',
        'file_ref' => 's3://bucket/receipt-002.pdf',
        'checksum' => 'sha256-receipt-002',
        'mime_type' => 'application/pdf',
    ]);
    expect($doc2Store->getStatusCode())->toBeIn([200, 201]);
    $doc2Id = (string) $doc2Store->json('data.id');

    $this->getJson('/api/v1/accounting/document-capture')->assertOk();
    $this->getJson('/api/v1/accounting/document-capture?status=uploaded')->assertOk();
    $this->getJson('/api/v1/accounting/document-capture?status=invalid_status')->assertStatus(422);
    $this->getJson("/api/v1/accounting/document-capture/{$doc1Id}")->assertOk();

    $this->postJson("/api/v1/accounting/document-capture/{$doc2Id}/duplicate", [
        'original_id' => (int) $doc1Id,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/document-capture/{$doc1Id}/extract", [
        'extracted_data' => [
            'lines' => [
                ['description' => 'Office supplies', 'amount' => 120.00],
            ],
        ],
        'confidence' => 0.96,
        'adapter' => 'ocr-v1',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/document-capture/{$doc1Id}/review", [
        'approved' => true,
        'reason' => null,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/document-capture/{$doc1Id}/archive")->assertOk();
});
