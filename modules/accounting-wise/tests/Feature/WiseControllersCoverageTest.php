<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\BankAccountBalance;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\BankFeedTransaction;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Services\WiseService;

function makeWiseUserAndTeam(): array
{
    $user = User::factory()->create();
    $team = Team::forceCreate([
        'user_id' => $user->id,
        'name' => 'Wise Team '.$user->id,
        'personal_team' => true,
    ]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return [$user, $team];
}

it('generates wise authorization url and handles exceptions', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user] = makeWiseUserAndTeam();

    $wise = Mockery::mock(WiseService::class);
    $wise->shouldReceive('getAuthorizationUrl')
        ->once()
        ->with(Mockery::type('string'))
        ->andReturn('https://sandbox.transferwise.tech/oauth/v2/authorize?state=xyz');
    $wise->shouldReceive('getAuthorizationUrl')
        ->once()
        ->andThrow(new Exception('Wise auth URL error'));
    $this->app->instance(WiseService::class, $wise);

    Sanctum::actingAs($user);

    $this->getJson('/api/wise/authorize')
        ->assertOk()
        ->assertJson([
            'success' => true,
            'authorization_url' => 'https://sandbox.transferwise.tech/oauth/v2/authorize?state=xyz',
        ]);

    expect(Cache::get('wise_oauth_state:'.$user->id))->not->toBeNull();

    $this->getJson('/api/wise/authorize')
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('handles wise oauth callback validation, state checks, creation, and rollback', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeWiseUserAndTeam();

    $wise = Mockery::mock(WiseService::class);
    $wise->shouldReceive('exchangeAuthorizationCode')
        ->once()
        ->with('wise-code-1')
        ->andReturn([
            'access_token' => 'wise-access-1',
            'refresh_token' => 'wise-refresh-1',
            'expires_in' => 3600,
        ]);
    $wise->shouldReceive('exchangeAuthorizationCode')
        ->once()
        ->with('wise-code-2')
        ->andReturn([
            'access_token' => 'wise-access-2',
        ]);
    $wise->shouldReceive('exchangeAuthorizationCode')
        ->once()
        ->with('wise-code-bad')
        ->andThrow(new Exception('Wise token exchange failed'));
    $this->app->instance(WiseService::class, $wise);

    Sanctum::actingAs($user);

    // 400 missing code
    $this->postJson('/api/wise/callback', [])
        ->assertStatus(400)
        ->assertJson(['success' => false, 'message' => 'Authorization code missing']);

    // 422 missing cached state
    $this->postJson('/api/wise/callback', ['code' => 'wise-code-1', 'state' => 'bad'])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Invalid OAuth state']);

    // 422 mismatched state
    Cache::put('wise_oauth_state:'.$user->id, 'expected-wise-state', 60);
    $this->postJson('/api/wise/callback', ['code' => 'wise-code-1', 'state' => 'wrong-state'])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Invalid OAuth state']);

    // 201 success with expires_in
    Cache::put('wise_oauth_state:'.$user->id, 'wise-state-1', 60);
    $this->postJson('/api/wise/callback', ['code' => 'wise-code-1', 'state' => 'wise-state-1'])
        ->assertCreated()
        ->assertJson([
            'success' => true,
            'connection' => ['institution_name' => 'Wise', 'status' => 'active'],
        ]);

    // 201 success without expires_in
    Cache::put('wise_oauth_state:'.$user->id, 'wise-state-2', 60);
    $this->postJson('/api/wise/callback', ['code' => 'wise-code-2', 'state' => 'wise-state-2'])
        ->assertCreated();

    expect(BankConnection::where('team_id', $team->id)->where('bank_id', 'wise')->count())->toBe(2);

    // 500 error & rollback
    Cache::put('wise_oauth_state:'.$user->id, 'wise-state-3', 60);
    $this->postJson('/api/wise/callback', ['code' => 'wise-code-bad', 'state' => 'wise-state-3'])
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('lists wise connections and handles query exceptions', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeWiseUserAndTeam();

    BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'wise',
        'institution_name' => 'Wise',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/wise/connections')
        ->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonCount(1, 'connections');

    $shouldThrow = true;
    BankConnection::retrieved(function () use (&$shouldThrow): void {
        if ($shouldThrow) {
            $shouldThrow = false;
            throw new Exception('Wise list DB error');
        }
    });

    $this->getJson('/api/wise/connections')
        ->assertStatus(500)
        ->assertJson(['success' => false, 'message' => 'Failed to retrieve connections']);
});

it('syncs wise accounts and balances with auth, status, and error handling', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeWiseUserAndTeam();
    [, $otherTeam] = makeWiseUserAndTeam();

    $activeConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'wise',
    ]);
    $inactiveConn = BankConnection::factory()->disconnected()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'wise',
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
        'bank_id' => 'wise',
    ]);

    $wise = Mockery::mock(WiseService::class);
    $wise->shouldReceive('getProfiles')
        ->once()
        ->andReturn([
            ['id' => 101, 'type' => 'business'],
        ]);
    $wise->shouldReceive('getBalances')
        ->once()
        ->with(Mockery::on(fn (BankConnection $c): bool => $c->id === $activeConn->id), 101)
        ->andReturn([
            [
                'id' => 5001,
                'currency' => 'EUR',
                'amount' => ['value' => 987.65],
            ],
        ]);
    $wise->shouldReceive('getProfiles')
        ->once()
        ->andThrow(new Exception('Wise profiles error'));
    $this->app->instance(WiseService::class, $wise);

    Sanctum::actingAs($user);

    $this->getJson("/api/wise/connections/{$otherConn->id}/accounts")->assertForbidden();
    $this->getJson("/api/wise/connections/{$inactiveConn->id}/accounts")->assertStatus(400);

    $this->getJson("/api/wise/connections/{$activeConn->id}/accounts")
        ->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonCount(1, 'accounts');

    expect(BankAccountBalance::where('bank_connection_id', $activeConn->id)->count())->toBe(1);

    $this->getJson("/api/wise/connections/{$activeConn->id}/accounts")
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('syncs wise transfers covering all statuses, dates, auth, and errors', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeWiseUserAndTeam();
    [, $otherTeam] = makeWiseUserAndTeam();

    $connNoLastSync = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'wise',
        'last_synced_at' => null,
    ]);
    $connWithLastSync = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'wise',
        'last_synced_at' => now()->subDay(),
    ]);
    $inactiveConn = BankConnection::factory()->disconnected()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'wise',
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
        'bank_id' => 'wise',
    ]);

    $wise = Mockery::mock(WiseService::class);
    $wise->shouldReceive('getProfiles')
        ->once()
        ->andReturn([['id' => 202, 'type' => 'business']]);
    $wise->shouldReceive('getTransfers')
        ->once()
        ->andReturn([
            ['id' => 1001, 'sourceValue' => 100.00, 'created' => '2026-07-01 10:00:00', 'reference' => 'Pay 1', 'status' => 'incoming_payment_waiting'],
            ['id' => 1002, 'sourceValue' => 200.00, 'created' => null, 'status' => 'processing'],
            ['id' => 1003, 'sourceValue' => 300.00, 'status' => 'outgoing_payment_sent'],
            ['id' => 1004, 'sourceValue' => 400.00, 'status' => 'funds_converted'],
            ['id' => 1005, 'sourceValue' => 500.00, 'status' => 'cancelled'],
            ['id' => 1006, 'sourceValue' => 600.00, 'status' => 'bounced_back'],
            ['id' => 1007, 'sourceValue' => 700.00, 'status' => 'funds_refunded'],
            ['id' => 1008, 'sourceValue' => 800.00, 'status' => 'some_unknown_status'],
        ]);
    $wise->shouldReceive('getProfiles')
        ->once()
        ->andReturn([]);
    $wise->shouldReceive('getProfiles')
        ->once()
        ->andThrow(new Exception('Wise sync error'));
    $this->app->instance(WiseService::class, $wise);

    Sanctum::actingAs($user);

    $this->postJson("/api/wise/connections/{$otherConn->id}/sync")->assertForbidden();
    $this->postJson("/api/wise/connections/{$inactiveConn->id}/sync")->assertStatus(400);

    $this->postJson("/api/wise/connections/{$connNoLastSync->id}/sync")
        ->assertOk()
        ->assertJson(['success' => true, 'summary' => ['total_processed' => 8]]);

    expect(Transaction::where('external_id', '1001')->first()?->status)->toBe('pending')
        ->and(Transaction::where('external_id', '1002')->first()?->status)->toBe('pending')
        ->and(Transaction::where('external_id', '1003')->first()?->status)->toBe('posted')
        ->and(Transaction::where('external_id', '1004')->first()?->status)->toBe('posted')
        ->and(Transaction::where('external_id', '1005')->first()?->status)->toBe('cancelled')
        ->and(Transaction::where('external_id', '1006')->first()?->status)->toBe('failed')
        ->and(Transaction::where('external_id', '1007')->first()?->status)->toBe('failed')
        ->and(Transaction::where('external_id', '1008')->first()?->status)->toBe('posted')
        ->and(BankFeedTransaction::where('bank_connection_id', $connNoLastSync->id)->count())->toBe(8);

    // Sync with last_synced_at populated
    $this->postJson("/api/wise/connections/{$connWithLastSync->id}/sync")
        ->assertOk()
        ->assertJson(['success' => true, 'summary' => ['total_processed' => 0]]);

    // Error rollback
    $this->postJson("/api/wise/connections/{$connNoLastSync->id}/sync")
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('removes wise connections with auth and exception handling', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeWiseUserAndTeam();
    [, $otherTeam] = makeWiseUserAndTeam();

    $conn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'wise',
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
        'bank_id' => 'wise',
    ]);

    Sanctum::actingAs($user);

    $this->deleteJson("/api/wise/connections/{$otherConn->id}")->assertForbidden();

    $this->deleteJson("/api/wise/connections/{$conn->id}")
        ->assertOk()
        ->assertJson(['success' => true]);
    expect($conn->fresh()->status)->toBe('disconnected');

    $conn2 = BankConnection::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'status' => 'active',
        'bank_id' => 'wise',
    ]);

    $shouldThrow = true;
    BankConnection::updating(function () use (&$shouldThrow): void {
        if ($shouldThrow) {
            $shouldThrow = false;
            throw new Exception('Wise update failed');
        }
    });

    $this->deleteJson("/api/wise/connections/{$conn2->id}")
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('handles wise webhooks for invalid signature, all events, and exceptions', function (): void {
    config()->set('services.wise.webhook_public_key', 'test-public-key');

    $wise = Mockery::mock(WiseService::class);
    $wise->shouldReceive('verifyWebhookSignature')->once()->andReturn(false);
    $wise->shouldReceive('verifyWebhookSignature')->times(5)->andReturn(true);
    $wise->shouldReceive('verifyWebhookSignature')->once()->andThrow(new Exception('Wise webhook crash'));
    $this->app->instance(WiseService::class, $wise);

    // 401 invalid signature
    $this->postJson('/api/webhooks/wise', ['event_type' => 'transfers#state-change'])
        ->assertUnauthorized()
        ->assertJson(['success' => false, 'message' => 'Invalid signature']);

    // transfers#state-change
    $this->postJson('/api/webhooks/wise', [
        'event_type' => 'transfers#state-change',
        'data' => ['resource' => ['id' => 11], 'current_state' => 'outgoing_payment_sent'],
    ])->assertOk()->assertJson(['success' => true]);

    // transfers#active-cases
    $this->postJson('/api/webhooks/wise', [
        'event_type' => 'transfers#active-cases',
        'data' => ['resource' => ['id' => 12]],
    ])->assertOk()->assertJson(['success' => true]);

    // balances#credit
    $this->postJson('/api/webhooks/wise', [
        'event_type' => 'balances#credit',
        'data' => ['resource' => ['id' => 13], 'currency' => 'GBP'],
    ])->assertOk()->assertJson(['success' => true]);

    // balances#update
    $this->postJson('/api/webhooks/wise', [
        'event_type' => 'balances#update',
        'data' => ['resource' => ['id' => 14]],
    ])->assertOk()->assertJson(['success' => true]);

    // Unknown event (default arm)
    $this->postJson('/api/webhooks/wise', [
        'event_type' => 'profiles#verification-state-change',
    ])->assertOk()->assertJson(['success' => true]);

    // Exception -> 200 with success: false
    $this->postJson('/api/webhooks/wise', [
        'event_type' => 'transfers#state-change',
    ])->assertOk()->assertJson(['success' => false, 'message' => 'Webhook processing failed']);
});
