<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\BillPaymentsApi\BillPaymentsApiServiceProvider;
use Liberu\Accounting\BillPaymentsApi\Policies\BillPaymentsPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-bill-payments-api provider, policy, controller, and resource', function (): void {
    $maker = User::factory()->create(['current_team_id' => 1]);
    $checker = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($maker, ['*']);

    $provider = $this->app->register(BillPaymentsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(BillPaymentsApiServiceProvider::class);

    $policy = new BillPaymentsPolicy();
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

    $storeRes = $this->postJson('/api/v1/accounting/bill-payments', [
        'supplier_id' => 10,
        'bill_reference' => 'BILL-PAY-001',
        'amount' => 750.00,
        'currency' => 'USD',
        'due_date' => '2026-02-28',
        'discount_date' => '2026-02-10',
        'discount_rate' => 2.0,
        'bank_details' => [
            'beneficiary_name' => 'Acme Supplier LLC',
            'iban' => 'GB29NWBK60161331926819',
        ],
        'provider' => 'manual',
        'idempotency_key' => 'bp-key-1',
        'metadata' => ['batch' => 'B1'],
    ]);
    expect($storeRes->status())->toBeLessThan(300);
    $paymentId = $storeRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/bill-payments')->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/bill-payments/{$paymentId}")->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/bill-payments/{$paymentId}/optimization")->status())->toBe(200);
    expect($this->postJson("/api/v1/accounting/bill-payments/{$paymentId}/request-approval")->status())->toBe(200);

    Sanctum::actingAs($checker, ['*']);
    expect($this->postJson("/api/v1/accounting/bill-payments/{$paymentId}/approve")->status())->toBe(200);

    Sanctum::actingAs($maker, ['*']);
    $secondRes = $this->postJson('/api/v1/accounting/bill-payments', [
        'supplier_id' => 10,
        'bill_reference' => 'BILL-PAY-002',
        'amount' => 300.00,
        'currency' => 'USD',
        'due_date' => '2026-03-15',
        'bank_details' => [
            'beneficiary_name' => 'Acme Supplier LLC',
            'account_number' => '12345678',
        ],
    ]);
    expect($secondRes->status())->toBeLessThan(300);
    $secondId = $secondRes->json('data.id');

    expect($this->postJson("/api/v1/accounting/bill-payments/{$secondId}/request-approval")->status())->toBe(200);
    expect($this->postJson("/api/v1/accounting/bill-payments/{$secondId}/reject", [
        'reason' => 'Duplicate invoice suspected',
    ])->status())->toBe(200);
});
