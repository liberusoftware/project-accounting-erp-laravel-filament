<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\BankAccounts\Enums\BankAccountType;
use Liberu\Accounting\BankAccounts\Models\BankAccount;
use Liberu\Accounting\BankFeedsApi\BankFeedsApiServiceProvider;
use Liberu\Accounting\BankFeedsApi\Policies\BankFeedsPolicy;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-bank-feeds-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(BankFeedsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(BankFeedsApiServiceProvider::class);

    $policy = new BankFeedsPolicy();
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

    $instRes = $this->postJson('/api/v1/accounting/bank-feeds/institutions', [
        'provider' => 'plaid',
        'external_id' => 'ins_109508',
        'name' => 'First Platypus Bank',
        'country' => 'US',
        'logo_url' => 'https://example.com/logo.png',
        'metadata' => ['tier' => 'gold'],
    ]);
    expect($instRes->status())->toBeLessThan(300);
    $institutionId = $instRes->json('id') ?? $instRes->json('data.id');

    $storeRes = $this->postJson('/api/v1/accounting/bank-feeds', [
        'institution_id' => $institutionId,
        'provider' => 'plaid',
        'name' => 'Main Feed',
        'external_reference' => 'conn-ext-001',
        'access_token' => 'secret-token-value',
        'credentials' => ['env' => 'sandbox'],
        'metadata' => ['auto_sync' => true],
    ]);
    expect($storeRes->status())->toBeLessThan(300);
    $connectionId = $storeRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/bank-feeds')->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/bank-feeds/{$connectionId}")->status())->toBe(200);

    $entity = LegalEntity::create([
        'name' => 'Bank Feeds Entity',
        'currency_code' => 'USD',
    ]);
    $bankAccount = BankAccount::create([
        'legal_entity_id' => $entity->id,
        'name' => 'Feed Linked Checking',
        'account_type' => BankAccountType::Bank,
        'currency' => 'USD',
        'opening_balance' => 0,
        'opening_date' => '2026-01-01',
        'current_balance' => 0,
    ]);

    $mapRes = $this->postJson("/api/v1/accounting/bank-feeds/{$connectionId}/mapping", [
        'bank_account_id' => $bankAccount->id,
        'external_account_id' => 'ext-acct-001',
        'name' => 'Checking Account',
        'currency' => 'USD',
        'metadata' => ['mask' => '1234'],
    ]);
    expect($mapRes->status())->toBeLessThan(300);

    $importRes = $this->postJson("/api/v1/accounting/bank-feeds/{$connectionId}/imports", [
        'added' => [
            [
                'external_id' => 'tx-ext-001',
                'amount' => 42.50,
                'currency' => 'USD',
                'transaction_date' => '2026-01-12',
                'description' => 'Cloud hosting',
            ],
        ],
        'modified' => [],
        'removed' => [],
        'next_cursor' => 'cursor-2',
    ]);
    expect($importRes->status())->toBe(202);
});
