<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\ClientCollaborationApi\ClientCollaborationApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-client-collaboration-api api provider and all routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ClientCollaborationApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ClientCollaborationApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/client-collaboration', [
        'thread_ref' => 'THR-2026-001',
        'kind' => 'document-request',
        'subject' => 'Q1 Bank Statements',
    ])->assertCreated();

    $threadId = $storeRes->json('data.id');
    expect($threadId)->not->toBeNull();

    $this->postJson("/api/v1/accounting/client-collaboration/{$threadId}/messages", [
        'body' => 'Please upload January bank statement.',
        'author_ref' => 'ACCOUNTANT-1',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/client-collaboration/{$threadId}/approvals", [
        'approver_ref' => 'PARTNER-1',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/client-collaboration/{$threadId}/evidence", [
        'reference' => 'DOC-2026-01',
    ])->assertOk();

    $this->getJson('/api/v1/accounting/client-collaboration')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
