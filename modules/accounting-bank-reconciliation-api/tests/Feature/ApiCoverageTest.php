<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\BankAccounts\Enums\BankAccountType;
use Liberu\Accounting\BankAccounts\Models\BankAccount;
use Liberu\Accounting\BankReconciliationApi\BankReconciliationApiServiceProvider;
use Liberu\Accounting\BankReconciliationApi\Policies\BankReconciliationPolicy;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-bank-reconciliation-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(BankReconciliationApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(BankReconciliationApiServiceProvider::class);

    $policy = new BankReconciliationPolicy();
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
        'name' => 'Bank Reconciliation Entity',
        'currency_code' => 'USD',
    ]);
    $bankAccount = BankAccount::create([
        'legal_entity_id' => $entity->id,
        'name' => 'Reconciliation Checking',
        'account_type' => BankAccountType::Bank,
        'currency' => 'USD',
        'opening_balance' => 1000.00,
        'opening_date' => '2026-01-01',
        'current_balance' => 1250.00,
    ]);

    $storeRes = $this->postJson('/api/v1/accounting/bank-reconciliation', [
        'bank_account_id' => $bankAccount->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'opening_balance' => 1000.00,
        'statement_balance' => 1250.00,
        'metadata' => ['period' => '2026-01'],
    ]);
    expect($storeRes->status())->toBeLessThan(300);
    $sessionId = $storeRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/bank-reconciliation')->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/bank-reconciliation/{$sessionId}")->status())->toBe(200);

    $entryRes = $this->postJson("/api/v1/accounting/bank-reconciliation/{$sessionId}/entries", [
        'kind' => 'match',
        'amount' => 250.00,
        'currency' => 'USD',
        'description' => 'Customer deposit',
    ]);
    expect($entryRes->status())->toBeLessThan(300);
    $entryId = $entryRes->json('id') ?? $entryRes->json('data.id');

    $confirmRes = $this->postJson("/api/v1/accounting/bank-reconciliation/{$sessionId}/entries/{$entryId}/confirm");
    expect($confirmRes->status())->toBe(200);

    expect($this->getJson("/api/v1/accounting/bank-reconciliation/{$sessionId}/summary")->status())->toBe(200);

    $signOffRes = $this->postJson("/api/v1/accounting/bank-reconciliation/{$sessionId}/sign-off");
    expect($signOffRes->status())->toBe(200);
});
