<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AutomationPackApi\AutomationPackApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-automation-pack-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AutomationPackApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AutomationPackApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/automation/recipes', [
        'name' => 'Auto-categorize bank fees',
        'trigger' => 'bank_transaction.imported',
        'action' => 'categorize_transaction',
        'schedule' => '0 * * * *',
        'idempotency_key' => 'recipe-key-1',
        'configuration' => ['category' => 'bank_fees'],
    ]);
    expect($storeRes->status())->toBe(201);
    $recipeId = $storeRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/automation/recipes?per_page=10')->status())->toBe(200);

    $simulateRes = $this->postJson("/api/v1/accounting/automation/recipes/{$recipeId}/simulate", [
        'payload' => ['amount' => 25.00, 'description' => 'Monthly fee'],
    ]);
    expect($simulateRes->status())->toBe(200);
});
