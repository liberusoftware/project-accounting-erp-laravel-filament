<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Jobs\SyncPlaidTransactionsJob;
use Liberu\Accounting\Erp\Models\BankAccountBalance;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\BankFeedTransaction;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Services\PlaidService;

function makePlaidUserAndTeam(): array
{
    $user = User::factory()->create();
    $team = Team::forceCreate([
        'user_id' => $user->id,
        'name' => 'Plaid Team '.$user->id,
        'personal_team' => true,
    ]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return [$user, $team];
}

it('creates plaid link tokens in initial mode, update mode, handles 404 and 500 errors', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makePlaidUserAndTeam();
    [, $otherTeam] = makePlaidUserAndTeam();

    $connection = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'plaid_access_token' => 'access-sandbox-token-1',
    ]);

    $otherConnection = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
        'plaid_access_token' => 'access-other-token',
    ]);

    $plaid = Mockery::mock(PlaidService::class);
    $plaid->shouldReceive('createLinkToken')
        ->once()
        ->with($user->id, 'en', null)
        ->andReturn(['link_token' => 'link-initial-1', 'expiration' => '2026-12-01T00:00:00Z']);
    $plaid->shouldReceive('createLinkToken')
        ->once()
        ->with($user->id, 'fr', 'access-sandbox-token-1')
        ->andReturn(['link_token' => 'link-update-1', 'expiration' => '2026-12-01T01:00:00Z']);
    $plaid->shouldReceive('createLinkToken')
        ->once()
        ->with($user->id, 'en', null)
        ->andThrow(new Exception('Plaid down'));
    $this->app->instance(PlaidService::class, $plaid);

    Sanctum::actingAs($user);

    // Initial mode
    $this->postJson('/api/plaid/create-link-token')
        ->assertOk()
        ->assertJson([
            'success' => true,
            'link_token' => 'link-initial-1',
            'expiration' => '2026-12-01T00:00:00Z',
        ]);

    // Update mode with valid team connection
    $this->postJson('/api/plaid/create-link-token', [
        'language' => 'fr',
        'connection_id' => $connection->id,
    ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'link_token' => 'link-update-1',
        ]);

    // Update mode with missing connection
    $this->postJson('/api/plaid/create-link-token', [
        'connection_id' => 999999,
    ])
        ->assertNotFound()
        ->assertJson(['success' => false]);

    // Update mode with another team's connection
    $this->postJson('/api/plaid/create-link-token', [
        'connection_id' => $otherConnection->id,
    ])
        ->assertNotFound()
        ->assertJson(['success' => false]);

    // Service exception -> 500
    $this->postJson('/api/plaid/create-link-token')
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('stores plaid connections with validation, success, and exception rollback', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makePlaidUserAndTeam();

    $plaid = Mockery::mock(PlaidService::class);
    $plaid->shouldReceive('exchangePublicToken')
        ->once()
        ->with('public-sandbox-good')
        ->andReturn(['access_token' => 'access-new-1', 'item_id' => 'item-new-1']);
    $plaid->shouldReceive('exchangePublicToken')
        ->once()
        ->with('public-sandbox-bad')
        ->andThrow(new Exception('Exchange failed'));
    $this->app->instance(PlaidService::class, $plaid);

    Sanctum::actingAs($user);

    // 422 validation error
    $this->postJson('/api/plaid/store-connection', [])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Validation failed']);

    // 201 success
    $this->postJson('/api/plaid/store-connection', [
        'public_token' => 'public-sandbox-good',
        'institution_id' => 'ins_123',
        'institution_name' => 'Test Bank',
        'accounts' => [['id' => 'acc_1']],
    ])
        ->assertCreated()
        ->assertJson([
            'success' => true,
            'connection' => [
                'institution_name' => 'Test Bank',
                'status' => 'active',
            ],
        ]);

    expect(BankConnection::where('team_id', $team->id)->where('plaid_item_id', 'item-new-1')->exists())->toBeTrue();

    // 500 catch & rollback
    $this->postJson('/api/plaid/store-connection', [
        'public_token' => 'public-sandbox-bad',
        'institution_id' => 'ins_123',
        'institution_name' => 'Test Bank',
        'accounts' => [['id' => 'acc_1']],
    ])
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('lists plaid connections and handles query errors', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makePlaidUserAndTeam();

    BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'institution_name' => 'Chase Bank',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/plaid/connections')
        ->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonCount(1, 'connections');

    // Trigger catch block via self-disarming retrieved listener
    $shouldThrow = true;
    BankConnection::retrieved(function () use (&$shouldThrow): void {
        if ($shouldThrow) {
            $shouldThrow = false;
            throw new Exception('Simulated DB failure');
        }
    });

    $this->getJson('/api/plaid/connections')
        ->assertStatus(500)
        ->assertJson(['success' => false, 'message' => 'Failed to retrieve connections']);
});

it('syncs plaid transactions including added, modified, removed, auth, status, and errors', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makePlaidUserAndTeam();
    [, $otherTeam] = makePlaidUserAndTeam();

    $activeConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'plaid_access_token' => 'access-sync-1',
    ]);
    $inactiveConn = BankConnection::factory()->disconnected()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
    ]);

    // Pre-create a transaction to be removed
    Transaction::create([
        'external_id' => 'tx-to-remove',
        'bank_connection_id' => $activeConn->id,
        'team_id' => $team->id,
        'transaction_date' => '2026-06-01',
        'amount' => 10.00,
        'type' => 'debit',
        'description' => 'Old Tx',
        'status' => 'posted',
    ]);

    $plaid = Mockery::mock(PlaidService::class);
    $plaid->shouldReceive('syncTransactions')
        ->once()
        ->with(Mockery::on(fn (BankConnection $c): bool => $c->id === $activeConn->id))
        ->andReturn([
            'added' => [
                [
                    'transaction_id' => 'tx-add-debit',
                    'date' => '2026-07-10',
                    'amount' => 42.50,
                    'name' => 'Coffee Shop',
                    'category' => ['Food and Drink', 'Coffee'],
                    'pending' => true,
                ],
                [
                    'transaction_id' => 'tx-add-credit',
                    'authorized_date' => '2026-07-11',
                    'amount' => -150.00,
                    'name' => 'Client Refund',
                    'category' => [],
                    'pending' => false,
                ],
            ],
            'modified' => [
                [
                    'transaction_id' => 'tx-add-debit',
                    'date' => '2026-07-10',
                    'amount' => 45.00,
                    'name' => 'Coffee Shop Updated',
                    'category' => ['Food'],
                    'pending' => false,
                ],
            ],
            'removed' => [
                ['transaction_id' => 'tx-to-remove'],
            ],
        ]);
    $plaid->shouldReceive('syncTransactions')
        ->once()
        ->andThrow(new Exception('Sync boom'));
    $this->app->instance(PlaidService::class, $plaid);

    Sanctum::actingAs($user);

    // 403 unauthorized team
    $this->postJson("/api/plaid/connections/{$otherConn->id}/sync")
        ->assertForbidden()
        ->assertJson(['success' => false, 'message' => 'Unauthorized']);

    // 400 inactive connection
    $this->postJson("/api/plaid/connections/{$inactiveConn->id}/sync")
        ->assertStatus(400)
        ->assertJson(['success' => false, 'message' => 'Connection is not active']);

    // 200 success
    $this->postJson("/api/plaid/connections/{$activeConn->id}/sync")
        ->assertOk()
        ->assertJson([
            'success' => true,
            'summary' => [
                'added' => 2,
                'modified' => 1,
                'removed' => 1,
                'total_processed' => 3,
            ],
        ]);

    expect(Transaction::where('external_id', 'tx-to-remove')->exists())->toBeFalse()
        ->and(Transaction::where('external_id', 'tx-add-debit')->first()?->category)->toBe('food')
        ->and(Transaction::where('external_id', 'tx-add-credit')->first()?->category)->toBe('uncategorized')
        ->and(BankFeedTransaction::where('bank_connection_id', $activeConn->id)->count())->toBe(2);

    // 500 error & rollback
    $this->postJson("/api/plaid/connections/{$activeConn->id}/sync")
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('removes plaid connections with and without token, checking auth and errors', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makePlaidUserAndTeam();
    [, $otherTeam] = makePlaidUserAndTeam();

    $connWithToken = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'plaid_access_token' => 'access-remove-1',
    ]);
    $connWithoutToken = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'plaid_access_token' => null,
    ]);
    $connFailing = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'plaid_access_token' => 'access-fail-1',
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
    ]);

    $plaid = Mockery::mock(PlaidService::class);
    $plaid->shouldReceive('removeItem')->once()->with('access-remove-1')->andReturn(true);
    $plaid->shouldReceive('removeItem')->once()->with('access-fail-1')->andThrow(new Exception('Remove failed'));
    $this->app->instance(PlaidService::class, $plaid);

    Sanctum::actingAs($user);

    $this->deleteJson("/api/plaid/connections/{$otherConn->id}")
        ->assertForbidden();

    $this->deleteJson("/api/plaid/connections/{$connWithToken->id}")
        ->assertOk()
        ->assertJson(['success' => true]);
    expect($connWithToken->fresh()->status)->toBe('disconnected');

    $this->deleteJson("/api/plaid/connections/{$connWithoutToken->id}")
        ->assertOk()
        ->assertJson(['success' => true]);
    expect($connWithoutToken->fresh()->status)->toBe('disconnected');

    $this->deleteJson("/api/plaid/connections/{$connFailing->id}")
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('fetches plaid balances with auth, status, currency fallbacks, and errors', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makePlaidUserAndTeam();
    [, $otherTeam] = makePlaidUserAndTeam();

    $activeConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'plaid_access_token' => 'access-bal-1',
    ]);
    $inactiveConn = BankConnection::factory()->disconnected()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
    ]);

    $plaid = Mockery::mock(PlaidService::class);
    $plaid->shouldReceive('getBalances')
        ->once()
        ->with('access-bal-1')
        ->andReturn([
            'accounts' => [
                [
                    'account_id' => 'acc-1',
                    'name' => 'Checking',
                    'type' => 'depository',
                    'subtype' => 'checking',
                    'balances' => [
                        'current' => 1200.50,
                        'available' => 1100.00,
                        'limit' => null,
                        'iso_currency_code' => 'USD',
                        'unofficial_currency_code' => null,
                    ],
                ],
                [
                    'account_id' => 'acc-2',
                    'name' => 'Crypto Vault',
                    'type' => 'investment',
                    'balances' => [
                        'current' => 5.00,
                        'unofficial_currency_code' => 'BTC',
                    ],
                ],
            ],
        ]);
    $plaid->shouldReceive('getBalances')
        ->once()
        ->andThrow(new Exception('Balances boom'));
    $this->app->instance(PlaidService::class, $plaid);

    Sanctum::actingAs($user);

    $this->getJson("/api/plaid/connections/{$otherConn->id}/balances")
        ->assertForbidden();

    $this->getJson("/api/plaid/connections/{$inactiveConn->id}/balances")
        ->assertStatus(400);

    $this->getJson("/api/plaid/connections/{$activeConn->id}/balances")
        ->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonCount(2, 'accounts');

    expect(BankAccountBalance::where('bank_connection_id', $activeConn->id)->count())->toBe(2);

    $this->getJson("/api/plaid/connections/{$activeConn->id}/balances")
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('handles plaid oauth redirects including missing state, success, and exception', function (): void {
    // 400 missing state
    $this->getJson('/api/plaid/oauth-redirect')
        ->assertStatus(400)
        ->assertJson(['success' => false, 'message' => 'Missing OAuth state ID']);

    // 200 success
    $this->getJson('/api/plaid/oauth-redirect?oauth_state_id=state-abc-123')
        ->assertOk()
        ->assertJson([
            'success' => true,
            'oauth_state_id' => 'state-abc-123',
        ]);

    // 500 catch block
    Log::shouldReceive('info')->once()->andThrow(new Exception('Logger failure'));
    Log::shouldReceive('error')->once();

    $this->getJson('/api/plaid/oauth-redirect?oauth_state_id=state-boom')
        ->assertStatus(500)
        ->assertJson(['success' => false, 'message' => 'Failed to handle OAuth redirect']);
});

it('handles all plaid webhook types, codes, invalid signatures, and exceptions', function (): void {
    Bus::fake();
    [$user, $team] = makePlaidUserAndTeam();

    $connection = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'plaid_item_id' => 'item-wh-1',
    ]);

    $plaid = Mockery::mock(PlaidService::class);
    $plaid->shouldReceive('verifyWebhookSignature')
        ->once()
        ->andReturn(false);
    $plaid->shouldReceive('verifyWebhookSignature')
        ->times(18)
        ->andReturn(true);
    $plaid->shouldReceive('verifyWebhookSignature')
        ->once()
        ->andThrow(new Exception('Webhook crash'));
    $this->app->instance(PlaidService::class, $plaid);

    // 1. Invalid signature -> 401
    $this->postJson('/api/webhooks/plaid', ['webhook_type' => 'TRANSACTIONS'])
        ->assertUnauthorized()
        ->assertJson(['success' => false, 'message' => 'Invalid signature']);

    // 2. TRANSACTIONS: connection not found
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'TRANSACTIONS',
        'webhook_code' => 'SYNC_UPDATES_AVAILABLE',
        'item_id' => 'item-missing',
    ])->assertOk();

    // 3-6. TRANSACTIONS: sync jobs dispatched
    foreach (['SYNC_UPDATES_AVAILABLE', 'INITIAL_UPDATE', 'HISTORICAL_UPDATE', 'DEFAULT_UPDATE'] as $code) {
        $this->postJson('/api/webhooks/plaid', [
            'webhook_type' => 'TRANSACTIONS',
            'webhook_code' => $code,
            'item_id' => 'item-wh-1',
        ])->assertOk()->assertJson(['success' => true]);
    }
    Bus::assertDispatched(SyncPlaidTransactionsJob::class, 4);

    // 7. TRANSACTIONS: TRANSACTIONS_REMOVED
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'TRANSACTIONS',
        'webhook_code' => 'TRANSACTIONS_REMOVED',
        'item_id' => 'item-wh-1',
        'removed_transactions' => ['tx-1', 'tx-2'],
    ])->assertOk();

    // 8. TRANSACTIONS: unknown code (default arm)
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'TRANSACTIONS',
        'webhook_code' => 'SOME_OTHER_CODE',
        'item_id' => 'item-wh-1',
    ])->assertOk();

    // 9. ITEM: connection not found
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'ITEM',
        'webhook_code' => 'ERROR',
        'item_id' => 'item-missing',
    ])->assertOk();

    // 10. ITEM: ERROR with null error (early return)
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'ITEM',
        'webhook_code' => 'ERROR',
        'item_id' => 'item-wh-1',
        'error' => null,
    ])->assertOk();

    // 11. ITEM: ERROR with ITEM_LOGIN_REQUIRED -> requires_reauth
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'ITEM',
        'webhook_code' => 'ERROR',
        'item_id' => 'item-wh-1',
        'error' => ['error_code' => 'ITEM_LOGIN_REQUIRED', 'error_message' => 'Reauth needed'],
    ])->assertOk();
    expect($connection->fresh()->status)->toBe('requires_reauth');

    // 12. ITEM: ERROR with ITEM_LOCKED -> locked
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'ITEM',
        'webhook_code' => 'ERROR',
        'item_id' => 'item-wh-1',
        'error' => ['error_code' => 'ITEM_LOCKED', 'error_message' => 'Locked'],
    ])->assertOk();
    expect($connection->fresh()->status)->toBe('locked');

    // 13. ITEM: WEBHOOK_UPDATE_ACKNOWLEDGED
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'ITEM',
        'webhook_code' => 'WEBHOOK_UPDATE_ACKNOWLEDGED',
        'item_id' => 'item-wh-1',
    ])->assertOk();

    // 14. ITEM: PENDING_EXPIRATION
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'ITEM',
        'webhook_code' => 'PENDING_EXPIRATION',
        'item_id' => 'item-wh-1',
    ])->assertOk();

    // 15. ITEM: USER_PERMISSION_REVOKED -> revoked
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'ITEM',
        'webhook_code' => 'USER_PERMISSION_REVOKED',
        'item_id' => 'item-wh-1',
    ])->assertOk();
    expect($connection->fresh()->status)->toBe('revoked');

    // 16. ITEM: unknown code (default arm)
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'ITEM',
        'webhook_code' => 'UNKNOWN_ITEM_CODE',
        'item_id' => 'item-wh-1',
    ])->assertOk();

    // 17. AUTH webhook
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'AUTH',
        'webhook_code' => 'AUTOMATICALLY_VERIFIED',
        'item_id' => 'item-wh-1',
    ])->assertOk();

    // 18. ASSETS webhook
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'ASSETS',
        'webhook_code' => 'PRODUCT_READY',
        'item_id' => 'item-wh-1',
    ])->assertOk();

    // 19. Unknown webhook type (default arm)
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'INVESTMENTS',
        'webhook_code' => 'DEFAULT_UPDATE',
        'item_id' => 'item-wh-1',
    ])->assertOk();

    // 20. Exception -> returns 200 with success: false
    $this->postJson('/api/webhooks/plaid', [
        'webhook_type' => 'TRANSACTIONS',
    ])
        ->assertOk()
        ->assertJson(['success' => false, 'message' => 'Webhook processing failed']);
});
