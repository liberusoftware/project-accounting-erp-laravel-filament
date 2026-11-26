<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CashCollectionAssistantApi\CashCollectionAssistantApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-cash-collection-assistant-api api provider and all routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CashCollectionAssistantApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CashCollectionAssistantApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/cash-collection-assistant', [
        'invoice_ref' => 'INV-2026-1001',
        'customer_ref' => 'CUST-001',
        'risk_score' => 65,
    ])->assertCreated();

    $assistantId = $storeRes->json('data.id');
    expect($assistantId)->not->toBeNull();

    $this->getJson('/api/v1/accounting/cash-collection-assistant')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->patchJson("/api/v1/accounting/cash-collection-assistant/{$assistantId}", [
        'risk_score' => 80,
        'policy_ref' => 'POL-STRICT',
        'approval_status' => 'approved',
        'outcome' => 'partial',
    ])->assertOk()
        ->assertJsonPath('data.risk_level', 'high');

    $this->postJson("/api/v1/accounting/cash-collection-assistant/{$assistantId}/reminder", [
        'reminder_at' => '2026-06-01 09:00:00',
        'draft' => 'Friendly payment reminder for INV-2026-1001.',
    ])->assertOk()
        ->assertJsonPath('data.reminder_status', 'scheduled');

    $this->postJson("/api/v1/accounting/cash-collection-assistant/{$assistantId}/promise", [
        'promised_date' => '2026-06-15',
        'promised_amount' => 500,
    ])->assertOk()
        ->assertJsonPath('data.promise_status', 'open');
});
