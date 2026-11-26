<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\SageConnection;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Services\SageService;
use Liberu\Accounting\SageAccountingMigrationApi\SageAccountingMigrationApiServiceProvider;

it('covers accounting-sage-accounting-migration-api provider, controllers, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(SageAccountingMigrationApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(SageAccountingMigrationApiServiceProvider::class);

    // 1. SageMigrationController + SageMigrationRunResource
    $runResponse = $this->postJson('/api/v1/accounting/sage-accounting-migration', [
        'records' => [
            ['entity_type' => 'customer', 'source_id' => 'SAGE-1', 'payload' => ['name' => 'Sage Customer']],
        ],
    ]);
    $runResponse->assertSuccessful();
    $runId = $runResponse->json('data.id');

    $this->getJson('/api/v1/accounting/sage-accounting-migration')->assertOk();
    $this->getJson("/api/v1/accounting/sage-accounting-migration/{$runId}")->assertOk();
    $this->postJson("/api/v1/accounting/sage-accounting-migration/{$runId}/reconcile")->assertOk();

    // 2. SageController
    $connection = SageConnection::query()->create([
        'user_id' => $user->id,
        'team_id' => 1,
        'business_id' => 'biz-123',
        'access_token' => 'sage-token',
        'refresh_token' => 'sage-refresh',
        'status' => 'active',
    ]);

    $this->mock(SageService::class, function ($mock) use ($connection): void {
        $mock->shouldReceive('getAuthorizationUrl')->once()->andReturn('https://www.sageone.com/oauth2/auth');
        $mock->shouldReceive('handleCallback')->once()->andReturn($connection);
        $mock->shouldReceive('sync')->once()->andReturn([
            'customers' => 1,
            'vendors' => 1,
            'invoices' => 2,
            'accounts' => 3,
            'bills' => 1,
            'payments' => 1,
            'estimates' => 0,
            'credit_memos' => 0,
            'transactions' => 5,
        ]);
    });

    $this->getJson('/api/sage/connect')->assertOk();
    $state = Cache::get('sage_oauth_state:'.$user->id);
    $this->getJson('/api/sage/callback?code=auth-code&state='.urlencode((string) $state))->assertOk();
    $this->getJson('/api/sage/connections')->assertOk();
    $this->postJson("/api/sage/connections/{$connection->id}/sync")->assertOk();
    $this->deleteJson("/api/sage/connections/{$connection->id}")->assertOk();
});
