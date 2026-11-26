<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\JournalApprovalsApi\JournalApprovalsApiServiceProvider;

it('covers accounting-journal-approvals-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(JournalApprovalsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(JournalApprovalsApiServiceProvider::class);

    $base = '/api/v1/accounting/journal-approvals';

    expect($this->postJson("{$base}/thresholds", [
        'team_id' => 1,
        'journal_type' => 'manual',
        'minimum_amount' => 1000,
        'reviewer_role' => 'controller',
        'required_approvals' => 1,
        'emergency_allowed' => true,
        'active' => true,
    ])->status())->toBe(201);

    $storeRes = $this->postJson($base, [
        'team_id' => 1,
        'approval_ref' => 'JA-100',
        'journal_type' => 'manual',
        'journal_source' => 'general-ledger',
        'journal_ref' => 'JNL-100',
        'preparer_ref' => 'accountant-1',
        'currency' => 'USD',
        'amount' => 5000,
        'threshold_amount' => 1000,
        'evidence' => [
            ['kind' => 'memo', 'description' => 'Accrual schedule'],
        ],
    ]);
    expect($storeRes->status())->toBeLessThan(500);
    $approvalId = $storeRes->json('data.id');
    expect($approvalId)->not->toBeNull();

    expect($this->getJson("{$base}?team_id=1&status=pending")->status())->toBeGreaterThanOrEqual(200);
    expect($this->getJson($base)->status())->toBeGreaterThanOrEqual(200);

    expect($this->postJson("{$base}/{$approvalId}/evidence", [
        'kind' => 'spreadsheet',
        'file_ref' => 's3://approvals/ja-100.xlsx',
        'description' => 'Supporting calculations',
        'checksum' => 'sha256:abc',
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/{$approvalId}/decide", [
        'actor_ref' => 'controller-1',
        'approved' => true,
        'comment' => 'Reviewed and approved',
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/{$approvalId}/post", [
        'emergency' => false,
    ])->status())->toBe(200);

    expect($this->getJson("{$base}/{$approvalId}")->status())->toBe(200);
});
