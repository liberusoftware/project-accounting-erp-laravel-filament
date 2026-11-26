<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CopilotApi\CopilotApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-copilot-api api provider, controllers, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CopilotApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CopilotApiServiceProvider::class);

    $store = $this->postJson('/api/v1/accounting/copilot/requests', [
        'kind' => 'search',
        'prompt' => 'Summarize open receivables for Q1',
        'result' => ['summary' => 'ok'],
        'confirmation_key' => 'confirm-key-001',
        'metadata' => ['source' => 'api'],
    ]);
    $store->assertStatus(201);
    $requestId = (string) $store->json('data.id');

    $this->getJson('/api/v1/accounting/copilot/requests')->assertOk();
    $this->getJson('/api/v1/accounting/copilot/requests?kind=search')->assertOk();

    $this->postJson("/api/v1/accounting/copilot/requests/{$requestId}/confirm", [
        'confirmation_key' => 'confirm-key-001',
    ])->assertOk();

    $userWithoutTeam = User::factory()->create(['current_team_id' => null]);
    Sanctum::actingAs($userWithoutTeam, ['*']);
    $this->getJson('/api/v1/accounting/copilot/requests')->assertForbidden();
});
