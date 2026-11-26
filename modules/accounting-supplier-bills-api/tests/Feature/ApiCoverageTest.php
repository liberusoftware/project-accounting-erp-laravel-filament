<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Models\Vendor;
use Liberu\Accounting\FinancialMasterData\Enums\PartyType;
use Liberu\Accounting\FinancialMasterData\Models\Party;
use Liberu\Accounting\SupplierBillsApi\Policies\SupplierBillsPolicy;
use Liberu\Accounting\SupplierBillsApi\SupplierBillsApiServiceProvider;

it('covers accounting-supplier-bills-api provider, policy, controllers, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(SupplierBillsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(SupplierBillsApiServiceProvider::class);

    $policy = new SupplierBillsPolicy();
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
    $stub = (object) ['id' => 1];
    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $stub))->toBeTrue();
    expect($policy->view($denyUser, $stub))->toBeFalse();
    expect($policy->view(null, $stub))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $stub))->toBeTrue();
    expect($policy->update($denyUser, $stub))->toBeFalse();
    expect($policy->update(null, $stub))->toBeFalse();

    // 1. SupplierBillController + SupplierBillResource
    $entity = LegalEntity::query()->create([
        'name' => 'Bills Entity',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
    $party = Party::query()->create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Supplier,
        'reference' => 'SUP-100',
        'name' => 'Acme Supplier',
    ]);

    $bill1 = $this->postJson('/api/v1/accounting/supplier-bills', [
        'party_id' => $party->id,
        'bill_number' => 'SB-001',
        'bill_date' => '2026-02-01',
        'due_on' => '2026-03-01',
        'currency' => 'GBP',
        'lines' => [
            ['account_code' => '5000', 'description' => 'Hosting', 'quantity' => 1, 'unit_price' => 400.00, 'tax_rate' => 20],
        ],
    ]);
    $bill1->assertSuccessful();
    $bill1Id = $bill1->json('data.id');

    $this->getJson("/api/v1/accounting/supplier-bills?party_id={$party->id}&status=draft")->assertOk();
    $this->patchJson("/api/v1/accounting/supplier-bills/{$bill1Id}", [
        'party_id' => $party->id,
        'bill_date' => '2026-02-01',
        'currency' => 'GBP',
        'notes' => 'Updated bill',
        'lines' => [
            ['account_code' => '5000', 'description' => 'Hosting Updated', 'quantity' => 1, 'unit_price' => 450.00, 'tax_rate' => 20],
        ],
    ])->assertOk();

    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/documents", [
        'path' => 'bills/sb-001.pdf',
        'original_name' => 'sb-001.pdf',
        'mime_type' => 'application/pdf',
        'sha256' => str_repeat('a', 64),
    ])->assertOk();

    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/approve")->assertOk();

    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/matches", [
        'match_type' => 'po',
        'matched_type' => 'purchase_order',
        'matched_id' => 'PO-001',
        'quantity' => 1,
        'amount' => 450.00,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/post")->assertOk();

    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/credits", [
        'amount' => 50.00,
        'currency' => 'GBP',
        'reason' => 'SLA credit',
        'reference' => 'CR-001',
    ])->assertOk();

    $this->getJson("/api/v1/accounting/supplier-bills/{$bill1Id}")->assertOk();
    $this->getJson("/api/v1/accounting/supplier-bills/duplicates?party_id={$party->id}&bill_number=SB-001")->assertOk();
    $this->getJson("/api/v1/accounting/supplier-bills/aging?party_id={$party->id}")->assertOk();
    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/void", ['reason' => 'Duplicate entry'])->assertOk();

    // Second bill for reject()
    $bill2 = $this->postJson('/api/v1/accounting/supplier-bills', [
        'party_id' => $party->id,
        'bill_number' => 'SB-002',
        'bill_date' => '2026-02-02',
        'currency' => 'GBP',
        'lines' => [
            ['description' => 'Rejected item', 'quantity' => 1, 'unit_price' => 100.00],
        ],
    ]);
    $bill2->assertSuccessful();
    $bill2Id = $bill2->json('data.id');
    $this->postJson("/api/v1/accounting/supplier-bills/{$bill2Id}/reject", ['reason' => 'Incorrect amount'])->assertOk();

    // 2. BillController (legacy /api/bills)
    $vendor = Vendor::factory()->create(['team_id' => 1]);
    $legacyBill = $this->postJson('/api/bills', [
        'vendor_id' => $vendor->getKey(),
        'bill_date' => '2026-02-01',
        'due_date' => '2026-03-01',
        'total_amount' => 300.00,
        'reference_number' => 'REF-001',
    ]);
    $legacyBill->assertCreated();
    $legacyBillId = $legacyBill->json('bill_id') ?? $legacyBill->json('id');

    $this->getJson('/api/bills')->assertOk();
    $this->getJson("/api/bills/{$legacyBillId}")->assertOk();
    $this->putJson("/api/bills/{$legacyBillId}", ['notes' => 'Updated legacy bill'])->assertOk();
    $this->deleteJson("/api/bills/{$legacyBillId}")->assertOk();
});
