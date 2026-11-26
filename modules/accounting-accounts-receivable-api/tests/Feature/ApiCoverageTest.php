<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AccountsReceivableApi\AccountsReceivableApiServiceProvider;
use Liberu\Accounting\AccountsReceivableApi\Http\Resources\ReceivableDisputeResource;
use Liberu\Accounting\AccountsReceivableApi\Http\Resources\ReceivableOpenItemResource;
use Liberu\Accounting\AccountsReceivableApi\Http\Resources\ReceivableReceiptResource;
use Liberu\Accounting\AccountsReceivableApi\Policies\AccountsReceivablePolicy;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FinancialMasterData\Enums\PartyType;
use Liberu\Accounting\FinancialMasterData\Models\Party;

it('covers accounting-accounts-receivable-api provider, policy, controller, and resources', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AccountsReceivableApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AccountsReceivableApiServiceProvider::class);

    $policy = new AccountsReceivablePolicy();
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

    expect((new ReceivableOpenItemResource(null))->toArray(request()))->toBe([]);
    expect((new ReceivableReceiptResource(null))->toArray(request()))->toBe([]);
    expect((new ReceivableDisputeResource(null))->toArray(request()))->toBe([]);

    $entity = LegalEntity::create([
        'name' => 'AR API Test Entity',
        'currency_code' => 'USD',
    ]);
    $customer = Party::create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Customer,
        'name' => 'Acme Customer',
    ]);

    $openItemRes = $this->postJson('/api/v1/accounting/accounts-receivable', [
        'party_id' => $customer->id,
        'reference' => 'INV-001',
        'issued_on' => '2026-01-10',
        'due_on' => '2026-02-10',
        'original_amount' => 500.00,
        'currency' => 'USD',
        'metadata' => ['source' => 'api'],
    ]);
    expect($openItemRes->status())->toBeLessThan(300);
    $openItemId = $openItemRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/accounts-receivable')->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/accounts-receivable?party_id={$customer->id}")->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/accounts-receivable/{$openItemId}")->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/accounts-receivable/statement/{$customer->id}?from=2026-01-01&to=2026-12-31")->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/accounts-receivable/aging?party_id={$customer->id}&as_of=2026-02-15")->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/accounts-receivable/balances/{$customer->id}")->status())->toBe(200);
    expect($this->getJson('/api/v1/accounting/accounts-receivable/reconciliation?control_balance=500')->status())->toBe(200);

    $receiptRes = $this->postJson('/api/v1/accounting/accounts-receivable/receipts', [
        'party_id' => $customer->id,
        'received_on' => '2026-01-20',
        'amount' => 200.00,
        'currency' => 'USD',
        'reference' => 'RCPT-001',
    ]);
    expect($receiptRes->status())->toBe(201);
    $receiptId = $receiptRes->json('data.id');

    expect($this->getJson("/api/v1/accounting/accounts-receivable/receipts?party_id={$customer->id}")->status())->toBe(200);

    $applyRes = $this->postJson("/api/v1/accounting/accounts-receivable/receipts/{$receiptId}/apply", [
        'open_item_id' => $openItemId,
        'amount' => 150.00,
    ]);
    expect($applyRes->status())->toBe(200);

    $disputeRes = $this->postJson('/api/v1/accounting/accounts-receivable/disputes', [
        'open_item_id' => $openItemId,
        'reason' => 'Damaged goods',
        'amount' => 50.00,
    ]);
    expect($disputeRes->status())->toBe(201);
    $disputeId = $disputeRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/accounts-receivable/disputes?status=open')->status())->toBe(200);

    $resolveRes = $this->postJson("/api/v1/accounting/accounts-receivable/disputes/{$disputeId}/resolve", [
        'resolution' => 'Allowance granted',
        'accepted' => true,
    ]);
    expect($resolveRes->status())->toBe(200);

    $creditRes = $this->postJson("/api/v1/accounting/accounts-receivable/customers/{$customer->id}/credit-control", [
        'credit_limit' => 10000.00,
        'credit_hold' => false,
        'hold_reason' => 'Approved',
    ]);
    expect($creditRes->status())->toBe(200);
});
