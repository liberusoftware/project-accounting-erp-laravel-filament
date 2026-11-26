<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\ApprovalRule;
use Liberu\Accounting\Erp\Models\BankAccountBalance;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\BankFeedTransaction;
use Liberu\Accounting\Erp\Models\OutboundPayment;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Services\RevolutService;

function makeRevolutUserAndTeam(): array
{
    $user = User::factory()->create();
    $team = Team::forceCreate([
        'user_id' => $user->id,
        'name' => 'Revolut Team '.$user->id,
        'personal_team' => true,
    ]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return [$user, $team];
}

it('generates revolut authorization url and handles exceptions', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user] = makeRevolutUserAndTeam();

    $revolut = Mockery::mock(RevolutService::class);
    $revolut->shouldReceive('getAuthorizationUrl')
        ->once()
        ->with(Mockery::type('string'))
        ->andReturn('https://sandbox-business.revolut.com/app-confirm?state=abc');
    $revolut->shouldReceive('getAuthorizationUrl')
        ->once()
        ->andThrow(new Exception('Auth URL error'));
    $this->app->instance(RevolutService::class, $revolut);

    Sanctum::actingAs($user);

    $this->getJson('/api/revolut/authorize')
        ->assertOk()
        ->assertJson([
            'success' => true,
            'authorization_url' => 'https://sandbox-business.revolut.com/app-confirm?state=abc',
        ]);

    expect(Cache::get('revolut_oauth_state:'.$user->id))->not->toBeNull();

    $this->getJson('/api/revolut/authorize')
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('handles revolut oauth callback validation, state checks, creation, and rollback', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeRevolutUserAndTeam();

    $revolut = Mockery::mock(RevolutService::class);
    $revolut->shouldReceive('exchangeAuthorizationCode')
        ->once()
        ->with('code-ok-1')
        ->andReturn([
            'access_token' => 'rev-access-1',
            'refresh_token' => 'rev-refresh-1',
            'expires_in' => 2400,
        ]);
    $revolut->shouldReceive('exchangeAuthorizationCode')
        ->once()
        ->with('code-ok-2')
        ->andReturn([
            'access_token' => 'rev-access-2',
        ]);
    $revolut->shouldReceive('exchangeAuthorizationCode')
        ->once()
        ->with('code-bad')
        ->andThrow(new Exception('Token exchange error'));
    $this->app->instance(RevolutService::class, $revolut);

    Sanctum::actingAs($user);

    // 400 missing code
    $this->postJson('/api/revolut/callback', [])
        ->assertStatus(400)
        ->assertJson(['success' => false, 'message' => 'Authorization code missing']);

    // 422 missing cached state
    $this->postJson('/api/revolut/callback', ['code' => 'code-1', 'state' => 'wrong'])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Invalid OAuth state']);

    // 422 mismatched state
    Cache::put('revolut_oauth_state:'.$user->id, 'expected-state', 60);
    $this->postJson('/api/revolut/callback', ['code' => 'code-1', 'state' => 'other-state'])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Invalid OAuth state']);

    // 201 success with expires_in
    Cache::put('revolut_oauth_state:'.$user->id, 'valid-state-1', 60);
    $this->postJson('/api/revolut/callback', ['code' => 'code-ok-1', 'state' => 'valid-state-1'])
        ->assertCreated()
        ->assertJson([
            'success' => true,
            'connection' => ['institution_name' => 'Revolut Business', 'status' => 'active'],
        ]);

    // 201 success without expires_in
    Cache::put('revolut_oauth_state:'.$user->id, 'valid-state-2', 60);
    $this->postJson('/api/revolut/callback', ['code' => 'code-ok-2', 'state' => 'valid-state-2'])
        ->assertCreated();

    expect(BankConnection::where('team_id', $team->id)->where('bank_id', 'revolut')->count())->toBe(2);

    // 500 exchange error
    Cache::put('revolut_oauth_state:'.$user->id, 'valid-state-3', 60);
    $this->postJson('/api/revolut/callback', ['code' => 'code-bad', 'state' => 'valid-state-3'])
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('lists revolut connections and handles query exceptions', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeRevolutUserAndTeam();

    BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
        'institution_name' => 'Revolut Business',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/revolut/connections')
        ->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonCount(1, 'connections');

    $shouldThrow = true;
    BankConnection::retrieved(function () use (&$shouldThrow): void {
        if ($shouldThrow) {
            $shouldThrow = false;
            throw new Exception('Revolut list DB error');
        }
    });

    $this->getJson('/api/revolut/connections')
        ->assertStatus(500)
        ->assertJson(['success' => false, 'message' => 'Failed to retrieve connections']);
});

it('syncs revolut accounts with auth, status, and error handling', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeRevolutUserAndTeam();
    [, $otherTeam] = makeRevolutUserAndTeam();

    $activeConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
    ]);
    $inactiveConn = BankConnection::factory()->disconnected()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
        'bank_id' => 'revolut',
    ]);

    $revolut = Mockery::mock(RevolutService::class);
    $revolut->shouldReceive('getAccounts')
        ->once()
        ->andReturn([
            [
                'id' => 'rev-acc-1',
                'name' => 'GBP Main',
                'type' => 'current',
                'balance' => 2500.75,
                'currency' => 'GBP',
            ],
        ]);
    $revolut->shouldReceive('getAccounts')
        ->once()
        ->andThrow(new Exception('Accounts error'));
    $this->app->instance(RevolutService::class, $revolut);

    Sanctum::actingAs($user);

    $this->getJson("/api/revolut/connections/{$otherConn->id}/accounts")->assertForbidden();
    $this->getJson("/api/revolut/connections/{$inactiveConn->id}/accounts")->assertStatus(400);

    $this->getJson("/api/revolut/connections/{$activeConn->id}/accounts")
        ->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonCount(1, 'accounts');

    expect(BankAccountBalance::where('bank_connection_id', $activeConn->id)->count())->toBe(1);

    $this->getJson("/api/revolut/connections/{$activeConn->id}/accounts")
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('syncs revolut transactions covering all states, categories, dates, and errors', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeRevolutUserAndTeam();
    [, $otherTeam] = makeRevolutUserAndTeam();

    $connNoLastSync = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
        'last_synced_at' => null,
    ]);
    $connWithLastSync = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
        'last_synced_at' => now()->subDay(),
    ]);
    $inactiveConn = BankConnection::factory()->disconnected()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
        'bank_id' => 'revolut',
    ]);

    $revolut = Mockery::mock(RevolutService::class);
    $revolut->shouldReceive('getTransactions')
        ->once()
        ->andReturn([
            [
                'id' => 'rtx-1',
                'type' => 'transfer',
                'state' => 'pending',
                'completed_at' => '2026-07-10T10:00:00Z',
                'reference' => 'Invoice 1',
                'legs' => [['amount' => -120.50]],
            ],
            [
                'id' => 'rtx-2',
                'type' => 'card_payment',
                'state' => 'completed',
                'created_at' => '2026-07-11T10:00:00Z',
                'merchant' => ['name' => 'Coffee Co'],
                'legs' => [['amount' => 50.00]],
            ],
            [
                'id' => 'rtx-3',
                'type' => 'custom_type',
                'state' => 'declined',
                'merchant' => ['category' => 'Restaurants'],
                'legs' => [['amount' => -15.00]],
            ],
            [
                'id' => 'rtx-4',
                'type' => 'unknown_type',
                'state' => 'failed',
                'legs' => [['amount' => -20.00]],
            ],
            [
                'id' => 'rtx-5',
                'type' => 'fx',
                'state' => 'reverted',
                'legs' => [['amount' => 30.00]],
            ],
            [
                'id' => 'rtx-6',
                'type' => 'fee',
                'state' => 'unexpected_state',
                'legs' => [['amount' => -5.00]],
            ],
        ]);
    $revolut->shouldReceive('getTransactions')
        ->once()
        ->andReturn([]);
    $revolut->shouldReceive('getTransactions')
        ->once()
        ->andThrow(new Exception('Tx sync error'));
    $this->app->instance(RevolutService::class, $revolut);

    Sanctum::actingAs($user);

    $this->postJson("/api/revolut/connections/{$otherConn->id}/sync")->assertForbidden();
    $this->postJson("/api/revolut/connections/{$inactiveConn->id}/sync")->assertStatus(400);

    $this->postJson("/api/revolut/connections/{$connNoLastSync->id}/sync")
        ->assertOk()
        ->assertJson(['success' => true, 'summary' => ['total_processed' => 6]]);

    expect(Transaction::where('external_id', 'rtx-1')->first()?->status)->toBe('pending')
        ->and(Transaction::where('external_id', 'rtx-2')->first()?->status)->toBe('posted')
        ->and(Transaction::where('external_id', 'rtx-3')->first()?->status)->toBe('declined')
        ->and(Transaction::where('external_id', 'rtx-3')->first()?->category)->toBe('restaurants')
        ->and(Transaction::where('external_id', 'rtx-4')->first()?->status)->toBe('failed')
        ->and(Transaction::where('external_id', 'rtx-4')->first()?->category)->toBe('uncategorized')
        ->and(Transaction::where('external_id', 'rtx-5')->first()?->status)->toBe('cancelled')
        ->and(Transaction::where('external_id', 'rtx-6')->first()?->status)->toBe('posted')
        ->and(BankFeedTransaction::where('bank_connection_id', $connNoLastSync->id)->count())->toBe(6);

    // Sync with last_synced_at populated
    $this->postJson("/api/revolut/connections/{$connWithLastSync->id}/sync")
        ->assertOk()
        ->assertJson(['success' => true, 'summary' => ['total_processed' => 0]]);

    // Error rollback
    $this->postJson("/api/revolut/connections/{$connNoLastSync->id}/sync")
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('removes revolut connections with auth and exception handling', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeRevolutUserAndTeam();
    [, $otherTeam] = makeRevolutUserAndTeam();

    $conn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
        'bank_id' => 'revolut',
    ]);

    Sanctum::actingAs($user);

    $this->deleteJson("/api/revolut/connections/{$otherConn->id}")->assertForbidden();

    $this->deleteJson("/api/revolut/connections/{$conn->id}")
        ->assertOk()
        ->assertJson(['success' => true]);
    expect($conn->fresh()->status)->toBe('disconnected');

    $conn2 = BankConnection::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'status' => 'active',
        'bank_id' => 'revolut',
    ]);

    $shouldThrow = true;
    BankConnection::updating(function () use (&$shouldThrow): void {
        if ($shouldThrow) {
            $shouldThrow = false;
            throw new Exception('Update failed');
        }
    });

    $this->deleteJson("/api/revolut/connections/{$conn2->id}")
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('sends single revolut payments covering sent, failed, pending approval, duplicates, auth, and errors', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeRevolutUserAndTeam();
    [, $otherTeam] = makeRevolutUserAndTeam();

    $activeConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
    ]);
    $inactiveConn = BankConnection::factory()->disconnected()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
        'bank_id' => 'revolut',
    ]);

    $revolut = Mockery::mock(RevolutService::class);
    $revolut->shouldReceive('sendPayment')
        ->once()
        ->andReturn(['id' => 'pay-ok-1', 'state' => 'completed']);
    $revolut->shouldReceive('sendPayment')
        ->once()
        ->andThrow(new Exception('Revolut declined'));
    $this->app->instance(RevolutService::class, $revolut);

    Sanctum::actingAs($user, ['payments:write']);

    $basePayload = [
        'account_id' => 'acc-1',
        'receiver' => ['counterparty_id' => 'cp-1'],
        'amount' => 50.00,
        'currency' => 'GBP',
        'reference' => 'Invoice #101',
    ];

    // 422 validation failure
    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", [])
        ->assertStatus(422);

    // 403 unauthorized team
    $this->postJson("/api/revolut/connections/{$otherConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'key-unauth']))
        ->assertForbidden();

    // 400 inactive connection
    $this->postJson("/api/revolut/connections/{$inactiveConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'key-inactive']))
        ->assertStatus(400);

    // 201 auto-approved and sent
    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'key-sent-1']))
        ->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => 'Payment sent successfully',
            'payment' => ['id' => 'pay-ok-1'],
        ]);

    // 409 duplicate idempotency key
    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'key-sent-1']))
        ->assertStatus(409)
        ->assertJson(['success' => false]);

    // 500 auto-approved but listener send failed -> STATUS_FAILED
    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'key-fail-1']))
        ->assertStatus(500)
        ->assertJson(['success' => false, 'message' => 'Payment failed to send']);

    // 202 above approval threshold -> queued for approval
    ApprovalRule::create([
        'team_id' => $team->id,
        'approvable_type' => OutboundPayment::class,
        'min_amount' => 100.00,
        'steps' => ['finance_manager'],
        'is_active' => true,
    ]);

    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", array_merge($basePayload, [
        'idempotency_key' => 'key-pending-1',
        'amount' => 250.00,
    ]))
        ->assertStatus(202)
        ->assertJson([
            'success' => true,
            'message' => 'Payment queued for approval',
            'status' => OutboundPayment::STATUS_PENDING_APPROVAL,
        ]);

    // 500 outer catch block
    $shouldThrow = true;
    OutboundPayment::creating(function () use (&$shouldThrow): void {
        if ($shouldThrow) {
            $shouldThrow = false;
            throw new Exception('Payment DB crash');
        }
    });

    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'key-crash-1']))
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('sends bulk revolut payments covering validation, auth, status, duplicates, success, and errors', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeRevolutUserAndTeam();
    [, $otherTeam] = makeRevolutUserAndTeam();

    $activeConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
    ]);
    $inactiveConn = BankConnection::factory()->disconnected()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
        'bank_id' => 'revolut',
    ]);

    $revolut = Mockery::mock(RevolutService::class);
    $revolut->shouldReceive('sendBulkPayment')
        ->once()
        ->andReturn(['id' => 'draft-1']);
    $revolut->shouldReceive('sendBulkPayment')
        ->once()
        ->andThrow(new Exception('Bulk failed'));
    $this->app->instance(RevolutService::class, $revolut);

    Sanctum::actingAs($user, ['payments:write']);

    $basePayload = [
        'title' => 'Monthly Payroll',
        'schedule_for' => '2026-08-01',
        'payments' => [
            [
                'account_id' => 'acc-1',
                'receiver' => ['counterparty_id' => 'cp-1'],
                'amount' => 100.00,
                'currency' => 'GBP',
                'reference' => 'Salary',
            ],
        ],
    ];

    $this->postJson("/api/revolut/connections/{$activeConn->id}/bulk-pay", [])->assertStatus(422);
    $this->postJson("/api/revolut/connections/{$otherConn->id}/bulk-pay", array_merge($basePayload, ['idempotency_key' => 'bk-1']))->assertForbidden();
    $this->postJson("/api/revolut/connections/{$inactiveConn->id}/bulk-pay", array_merge($basePayload, ['idempotency_key' => 'bk-2']))->assertStatus(400);

    $this->postJson("/api/revolut/connections/{$activeConn->id}/bulk-pay", array_merge($basePayload, ['idempotency_key' => 'bk-ok']))
        ->assertCreated()
        ->assertJson(['success' => true, 'payment_draft' => ['id' => 'draft-1']]);

    // 409 duplicate
    $this->postJson("/api/revolut/connections/{$activeConn->id}/bulk-pay", array_merge($basePayload, ['idempotency_key' => 'bk-ok']))
        ->assertStatus(409)
        ->assertJson(['success' => false]);

    // 500 error
    $this->postJson("/api/revolut/connections/{$activeConn->id}/bulk-pay", array_merge($basePayload, ['idempotency_key' => 'bk-fail']))
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('handles revolut webhooks for invalid signature, all events, and exceptions', function (): void {
    $revolut = Mockery::mock(RevolutService::class);
    $revolut->shouldReceive('verifyWebhookSignature')->once()->andReturn(false);
    $revolut->shouldReceive('verifyWebhookSignature')->times(3)->andReturn(true);
    $revolut->shouldReceive('verifyWebhookSignature')->once()->andThrow(new Exception('Webhook boom'));
    $this->app->instance(RevolutService::class, $revolut);

    // 401 invalid signature
    $this->postJson('/api/webhooks/revolut', ['event' => 'TransactionCreated'])
        ->assertUnauthorized()
        ->assertJson(['success' => false, 'message' => 'Invalid signature']);

    // TransactionCreated
    $this->postJson('/api/webhooks/revolut', [
        'event' => 'TransactionCreated',
        'data' => ['id' => 'tx-1'],
    ])->assertOk()->assertJson(['success' => true]);

    // TransactionStateChanged
    $this->postJson('/api/webhooks/revolut', [
        'event' => 'TransactionStateChanged',
        'data' => ['id' => 'tx-1', 'state' => 'completed'],
    ])->assertOk()->assertJson(['success' => true]);

    // Default / unknown event
    $this->postJson('/api/webhooks/revolut', [
        'event' => 'UnknownEvent',
    ])->assertOk()->assertJson(['success' => true]);

    // Exception -> 200 with success: false
    $this->postJson('/api/webhooks/revolut', [
        'event' => 'TransactionCreated',
    ])->assertOk()->assertJson(['success' => false, 'message' => 'Webhook processing failed']);
});
