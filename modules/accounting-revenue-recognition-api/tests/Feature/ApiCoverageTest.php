<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\RevenueRecognitionApi\RevenueRecognitionApiServiceProvider;

it('covers accounting-revenue-recognition-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(RevenueRecognitionApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(RevenueRecognitionApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/revenue-recognition', [
        'source_type' => 'sales_invoice',
        'source_id' => 'INV-REV-001',
        'description' => 'Annual SaaS Subscription',
        'currency' => 'GBP',
        'total_amount' => 1200.00,
        'start_date' => '2026-01-01',
        'periods' => 12,
        'deferred_account_ref' => '2400',
        'revenue_account_ref' => '4000',
        'funded' => true,
    ]);
    $storeResponse->assertCreated();
    $scheduleId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/revenue-recognition')->assertOk();
    $this->getJson("/api/v1/accounting/revenue-recognition/{$scheduleId}")->assertOk();

    $runResponse = $this->postJson("/api/v1/accounting/revenue-recognition/{$scheduleId}/recognize", [
        'as_of_date' => '2026-01-31',
    ]);
    $runResponse->assertSuccessful();
    $runId = $runResponse->json('id');

    $this->postJson("/api/v1/accounting/revenue-recognition/{$scheduleId}/modify", [
        'effective_date' => '2026-02-01',
        'amount_delta' => 100.00,
        'reason' => 'Plan upgrade',
    ])->assertSuccessful();

    $this->postJson("/api/v1/accounting/revenue-recognition/runs/{$runId}/reconcile", [
        'references' => [
            [
                'reference_type' => 'schedule',
                'reference_id' => (string) $scheduleId,
                'expected_amount' => 100.00,
                'recognized_amount' => 100.00,
            ],
        ],
    ])->assertOk();
});
