<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\QboConnection;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Services\QuickBooksService;
use Liberu\Accounting\QuickBooksOnlineMigrationApi\QuickBooksOnlineMigrationApiServiceProvider;

it('covers accounting-quickbooks-online-migration-api provider and all controllers', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(QuickBooksOnlineMigrationApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(QuickBooksOnlineMigrationApiServiceProvider::class);

    // 1. QboMigrationController
    $runResponse = $this->postJson('/api/v1/accounting/quickbooks-online-migration', [
        'records' => [
            ['entity_type' => 'customer', 'source_id' => 'QBO-1', 'payload' => ['name' => 'Acme']],
        ],
    ]);
    $runResponse->assertSuccessful();
    $runId = $runResponse->json('id');

    $this->getJson('/api/v1/accounting/quickbooks-online-migration')->assertOk();
    $this->getJson("/api/v1/accounting/quickbooks-online-migration/{$runId}")->assertOk();
    $this->postJson("/api/v1/accounting/quickbooks-online-migration/{$runId}/reconcile")->assertOk();

    // 2. QboController
    $connection = QboConnection::query()->create([
        'user_id' => $user->id,
        'team_id' => 1,
        'realm_id' => 'realm-123',
        'access_token' => 'token-abc',
        'refresh_token' => 'refresh-abc',
        'status' => 'active',
    ]);

    $this->mock(QuickBooksService::class, function ($mock) use ($connection): void {
        $mock->shouldReceive('getAuthorizationUrl')->once()->andReturn('https://appcenter.intuit.com/connect/oauth2');
        $mock->shouldReceive('handleCallback')->once()->andReturn($connection);
        $mock->shouldReceive('sync')->once()->andReturn([
            'customers' => 1,
            'vendors' => 0,
            'invoices' => 2,
            'accounts' => 3,
            'bills' => 0,
            'payments' => 1,
            'estimates' => 0,
            'credit_memos' => 0,
            'transactions' => 4,
        ]);
    });

    $this->getJson('/api/qbo/connect')->assertOk();
    $state = Cache::get('qbo_oauth_state:'.$user->id);
    $this->getJson('/api/qbo/callback?code=auth-code&realmId=realm-123&state='.urlencode((string) $state))->assertOk();
    $this->getJson('/api/qbo/connections')->assertOk();
    $this->postJson("/api/qbo/connections/{$connection->id}/sync")->assertOk();

    // 3. QboWebhookController
    Queue::fake();
    config()->set('services.qbo.webhook_verifier_token', 'test-qbo-secret');
    $this->postJson('/api/webhooks/qbo', ['eventNotifications' => []])->assertUnauthorized();

    $payload = json_encode(['eventNotifications' => [['realmId' => 'realm-123']]], JSON_THROW_ON_ERROR);
    $signature = base64_encode(hash_hmac('sha256', $payload, 'test-qbo-secret', true));
    $this->call('POST', '/api/webhooks/qbo', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_INTUIT_SIGNATURE' => $signature,
    ], $payload)->assertOk();

    // Clean up connection via removeConnection
    $this->deleteJson("/api/qbo/connections/{$connection->id}")->assertOk();
});
