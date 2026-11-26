<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Models\XeroConnection;
use Liberu\Accounting\Erp\Services\XeroService;
use Liberu\Accounting\XeroMigrationApi\XeroMigrationApiServiceProvider;

it('covers accounting-xero-migration-api provider and all controllers', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(XeroMigrationApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(XeroMigrationApiServiceProvider::class);

    // 1. XeroMigrationController
    $connResponse = $this->postJson('/api/v1/accounting/xero-migration/connections', [
        'tenant_ref' => 'xero-tenant-001',
        'access_token' => 'xero-access-token',
        'refresh_token' => 'xero-refresh-token',
    ]);
    $connResponse->assertCreated();
    $migConnId = $connResponse->json('id');

    $this->getJson('/api/v1/accounting/xero-migration/connections')->assertOk();
    $this->postJson("/api/v1/accounting/xero-migration/connections/{$migConnId}/records", [
        'source_type' => 'invoice',
        'source_id' => 'XERO-INV-1',
        'status' => 'migrated',
        'payload' => ['total' => 250.00],
    ])->assertCreated();

    // 2. XeroController
    $erpConnection = XeroConnection::query()->create([
        'user_id' => $user->id,
        'team_id' => 1,
        'tenant_id' => 'tenant-xyz',
        'access_token' => 'xero-token',
        'refresh_token' => 'xero-refresh',
        'status' => 'active',
    ]);

    $this->mock(XeroService::class, function ($mock) use ($erpConnection): void {
        $mock->shouldReceive('getAuthorizationUrl')->once()->andReturn('https://login.xero.com/identity/connect/authorize');
        $mock->shouldReceive('handleCallback')->once()->andReturn($erpConnection);
        $mock->shouldReceive('sync')->once()->andReturn([
            'customers' => 2,
            'vendors' => 1,
            'invoices' => 3,
            'accounts' => 4,
            'bills' => 1,
            'payments' => 2,
            'estimates' => 0,
            'credit_memos' => 0,
            'transactions' => 6,
        ]);
    });

    $this->getJson('/api/xero/connect')->assertOk();
    $state = Cache::get('xero_oauth_state:'.$user->id);
    $this->getJson('/api/xero/callback?code=auth-code&state='.urlencode((string) $state))->assertOk();
    $this->getJson('/api/xero/connections')->assertOk();
    $this->postJson("/api/xero/connections/{$erpConnection->id}/sync")->assertOk();

    // 3. XeroWebhookController
    Queue::fake();
    config()->set('services.xero.webhook_key', 'test-xero-key');
    $this->postJson('/api/webhooks/xero', ['events' => []])->assertUnauthorized();

    $payload = json_encode(['events' => [['tenantId' => 'tenant-xyz']]], JSON_THROW_ON_ERROR);
    $signature = base64_encode(hash_hmac('sha256', $payload, 'test-xero-key', true));
    $this->call('POST', '/api/webhooks/xero', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_XERO_SIGNATURE' => $signature,
    ], $payload)->assertOk();

    // Clean up connection via removeConnection
    $this->deleteJson("/api/xero/connections/{$erpConnection->id}")->assertOk();
});
