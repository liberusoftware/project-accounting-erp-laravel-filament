<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AccountsPayable\Models\PayableAccount;
use Liberu\Accounting\AccountsPayableApi\AccountsPayableApiServiceProvider;
use Liberu\Accounting\AccountsPayableApi\Http\Resources\PayableDisputeResource;
use Liberu\Accounting\AccountsPayableApi\Http\Resources\PayablePaymentResource;
use Liberu\Accounting\AccountsPayableApi\Policies\AccountingAccountsPayablePolicy;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FinancialMasterData\Enums\PartyType;
use Liberu\Accounting\FinancialMasterData\Models\Party;

it('covers accounting-accounts-payable-api provider, policy, controller, and resources', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AccountsPayableApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AccountsPayableApiServiceProvider::class);

    $policy = new AccountingAccountsPayablePolicy();
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

    expect((new PayableDisputeResource(null))->toArray(request()))->toBe([]);
    expect((new PayablePaymentResource(null))->toArray(request()))->toBe([]);

    $entity = LegalEntity::create([
        'name' => 'AP API Test Entity',
        'currency_code' => 'USD',
    ]);
    $supplier = Party::create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Supplier,
        'name' => 'Acme Supplier',
    ]);

    $openItemRes = $this->postJson('/api/v1/accounting/accounts-payable/open-items', [
        'party_id' => $supplier->id,
        'reference' => 'BILL-001',
        'issued_on' => '2026-01-10',
        'due_on' => '2026-02-10',
        'original_amount' => 500.00,
        'currency' => 'USD',
        'payment_terms' => 'NET30',
        'metadata' => ['note' => 'test'],
    ]);
    expect($openItemRes->status())->toBeLessThan(300);
    $openItemId = $openItemRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/accounts-payable')->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/accounts-payable?party_id={$supplier->id}")->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/accounts-payable/open-items/{$openItemId}")->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/accounts-payable/aging?party_id={$supplier->id}&as_of=2026-02-15")->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/accounts-payable/balances/{$supplier->id}")->status())->toBe(200);
    expect($this->getJson('/api/v1/accounting/accounts-payable/reconciliation')->status())->toBe(200);

    $paymentRes = $this->postJson('/api/v1/accounting/accounts-payable/payments', [
        'party_id' => $supplier->id,
        'paid_on' => '2026-01-20',
        'amount' => 200.00,
        'currency' => 'USD',
        'reference' => 'PAY-001',
    ]);
    expect($paymentRes->status())->toBe(201);
    $paymentId = $paymentRes->json('data.id');

    $applyRes = $this->postJson("/api/v1/accounting/accounts-payable/payments/{$paymentId}/apply", [
        'open_item_id' => $openItemId,
        'amount' => 150.00,
    ]);
    expect($applyRes->status())->toBe(200);

    $disputeRes = $this->postJson('/api/v1/accounting/accounts-payable/disputes', [
        'open_item_id' => $openItemId,
        'reason' => 'Price discrepancy',
        'amount' => 50.00,
    ]);
    expect($disputeRes->status())->toBe(201);
    $disputeId = $disputeRes->json('data.id');

    $resolveRes = $this->postJson("/api/v1/accounting/accounts-payable/disputes/{$disputeId}/resolve", [
        'resolution' => 'Credit note issued',
        'accepted' => true,
    ]);
    expect($resolveRes->status())->toBe(200);

    Gate::policy(PayableAccount::class, AccountingAccountsPayablePolicy::class);
    $controlRes = $this->postJson("/api/v1/accounting/accounts-payable/suppliers/{$supplier->id}/payment-control", [
        'payment_hold' => true,
        'hold_reason' => 'Audit review',
    ]);
    expect($controlRes->status())->toBe(200);
});
