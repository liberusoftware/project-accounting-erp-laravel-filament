<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\WorkpapersApi\WorkpapersApiServiceProvider;

it('covers accounting-workpapers-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(WorkpapersApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(WorkpapersApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/workpapers', [
        'title' => 'Cash & Bank Reconciliation',
        'reference' => 'WP-CASH-01',
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'preparer_id' => $user->id,
    ]);
    $storeResponse->assertCreated();
    $wpId = $storeResponse->json('id');

    $this->getJson('/api/v1/accounting/workpapers')->assertOk();

    $this->postJson("/api/v1/accounting/workpapers/{$wpId}/references", [
        'label' => 'Trial Balance 1000',
        'target_type' => 'account',
        'target_id' => '1000',
        'notes' => 'Agrees to bank statement',
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/workpapers/{$wpId}/attachments", [
        'name' => 'bank-statement.pdf',
        'disk' => 'local',
        'path' => 'workpapers/bank-statement.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/workpapers/{$wpId}/procedures", [
        'description' => 'Verify closing bank balance',
        'status' => 'passed',
        'performed_by' => $user->id,
        'performed_at' => '2026-12-31',
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/workpapers/{$wpId}/reviewer", [
        'reviewer_id' => $user->id,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/workpapers/{$wpId}/conclude", [
        'conclusion' => 'No material misstatements identified.',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/workpapers/{$wpId}/rollover", [
        'period_start' => '2027-01-01',
        'period_end' => '2027-12-31',
        'title' => 'Cash & Bank Reconciliation 2027',
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/workpapers/{$wpId}/exports", [
        'format' => 'pdf',
    ])->assertCreated();
});
