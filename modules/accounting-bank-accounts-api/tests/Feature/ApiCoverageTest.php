<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\BankAccountsApi\BankAccountsApiServiceProvider;
use Liberu\Accounting\BankAccountsApi\Policies\BankAccountsPolicy;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-bank-accounts-api provider, policy, controllers, and resources', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(BankAccountsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(BankAccountsApiServiceProvider::class);

    $policy = new BankAccountsPolicy();
    $allowUser = new class() extends User
    {
        public int $current_team_id = 1;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $denyUser = new class() extends User
    {
        public int $current_team_id = 2;

        public function tokenCan(string $a): bool
        {
            return false;
        }
    };
    $record = (object) ['team_id' => 1];

    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $record))->toBeTrue();
    expect($policy->view($denyUser, $record))->toBeFalse();
    expect($policy->view(null, $record))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeTrue();
    expect($policy->update($denyUser, $record))->toBeFalse();
    expect($policy->update(null, $record))->toBeFalse();

    $entity = LegalEntity::create([
        'name' => 'Bank Accounts API Entity',
        'currency_code' => 'USD',
    ]);

    $storeRes = $this->postJson('/api/v1/accounting/bank-accounts', [
        'legal_entity_id' => $entity->id,
        'name' => 'Operating Checking',
        'institution_name' => 'First National Bank',
        'account_type' => 'bank',
        'currency' => 'USD',
        'opening_balance' => 1000.00,
        'opening_date' => '2026-01-01',
        'account_number' => '1234567890',
        'routing_number' => '021000021',
        'feed_reference' => 'feed-001',
        'metadata' => ['branch' => 'HQ'],
    ]);
    expect($storeRes->status())->toBeLessThan(300);
    $accountId = $storeRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/bank-accounts')->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/bank-accounts?legal_entity_id={$entity->id}&status=active")->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/bank-accounts/{$accountId}")->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/bank-accounts/balances?legal_entity_id={$entity->id}")->status())->toBe(200);

    $updateRes = $this->patchJson("/api/v1/accounting/bank-accounts/{$accountId}", [
        'name' => 'Primary Operating Checking',
        'institution_name' => 'First National Bank',
        'account_type' => 'bank',
        'currency' => 'USD',
    ]);
    expect($updateRes->status())->toBe(200);

    $statusRes = $this->postJson("/api/v1/accounting/bank-accounts/{$accountId}/status", [
        'status' => 'inactive',
    ]);
    expect($statusRes->status())->toBe(200);

    $erpAccount = Account::factory()->create(['user_id' => $user->id]);

    $txStoreRes = $this->postJson('/api/transactions', [
        'account_id' => $erpAccount->id,
        'amount' => 125.50,
        'transaction_date' => '2026-01-15',
        'description' => 'Office supplies',
    ]);
    expect($txStoreRes->status())->toBeLessThan(300);
    $txId = $txStoreRes->json('data.id');

    expect($this->getJson('/api/transactions')->status())->toBe(200);
    expect($this->getJson("/api/transactions/{$txId}")->status())->toBe(200);
    expect($this->patchJson("/api/transactions/{$txId}", [
        'description' => 'Updated office supplies',
    ])->status())->toBe(200);
    expect($this->deleteJson("/api/transactions/{$txId}")->status())->toBe(204);
});
