<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FinancialMasterData\Enums\PartyType;
use Liberu\Accounting\FinancialMasterData\Models\Party;
use Liberu\Accounting\SalesInvoicing\Models\SalesInvoice;
use Liberu\Accounting\SalesInvoicingApi\Policies\SalesInvoicingPolicy;
use Liberu\Accounting\SalesInvoicingApi\SalesInvoicingApiServiceProvider;

it('covers accounting-sales-invoicing-api provider, policy, controllers, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(SalesInvoicingApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(SalesInvoicingApiServiceProvider::class);

    // 1. SalesInvoicingController + SalesInvoiceResource + SalesInvoicingPolicy
    $entity = LegalEntity::query()->create([
        'name' => 'Invoicing Entity',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
    $party = Party::query()->create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Customer,
        'reference' => 'CUST-100',
        'name' => 'Acme Customer',
    ]);

    $storeResponse = $this->postJson('/api/v1/accounting/sales-invoicing', [
        'party_id' => $party->id,
        'invoice_number' => 'SINV-001',
        'invoice_date' => '2026-02-01',
        'due_on' => '2026-03-01',
        'currency' => 'GBP',
        'lines' => [
            ['description' => 'Consulting', 'quantity' => 2, 'unit_price' => 500.00, 'discount_rate' => 0, 'tax_rate' => 20],
        ],
    ]);
    $storeResponse->assertCreated();
    $invId = $storeResponse->json('data.id');

    $invoiceModel = SalesInvoice::query()->findOrFail($invId);
    $policy = new SalesInvoicingPolicy();
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
    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $invoiceModel))->toBeTrue();
    expect($policy->view($denyUser, $invoiceModel))->toBeFalse();
    expect($policy->view(null, $invoiceModel))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $invoiceModel))->toBeTrue();
    expect($policy->update($denyUser, $invoiceModel))->toBeFalse();
    expect($policy->update(null, $invoiceModel))->toBeFalse();
    expect($policy->delete($allowUser, $invoiceModel))->toBeTrue();
    expect($policy->delete($denyUser, $invoiceModel))->toBeFalse();
    expect($policy->delete(null, $invoiceModel))->toBeFalse();

    $this->getJson('/api/v1/accounting/sales-invoicing')->assertOk();
    $this->getJson("/api/v1/accounting/sales-invoicing/{$invId}")->assertOk();

    // Trigger InvalidInvoice catch branches on Draft invoice
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/finalize")->assertUnprocessable();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/deposit", ['amount' => 100.00])->assertUnprocessable();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/deliver")->assertUnprocessable();

    // Valid lifecycle: approve -> approve again (catch) -> finalize -> deposit -> deliver -> destroy
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/approve")->assertOk();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/approve")->assertUnprocessable();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/finalize")->assertOk();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/deposit", ['amount' => 200.00, 'reference' => 'DEP-1'])->assertOk();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/deliver")->assertOk();
    $this->deleteJson("/api/v1/accounting/sales-invoicing/{$invId}")->assertNoContent();

    // 2. InvoiceController (legacy /api/invoices)
    $customer = Customer::factory()->create(['team_id' => 1]);
    $legacyCreate = $this->postJson('/api/invoices', [
        'customer_id' => $customer->getKey(),
        'invoice_date' => '2026-02-01',
        'due_date' => '2026-03-01',
        'total_amount' => 250.00,
        'notes' => 'Legacy invoice',
    ]);
    $legacyCreate->assertCreated();
    $legacyId = $legacyCreate->json('invoice_id') ?? $legacyCreate->json('id');

    $this->getJson('/api/invoices')->assertOk();
    $this->getJson("/api/invoices/{$legacyId}")->assertOk();
    $this->putJson("/api/invoices/{$legacyId}", ['notes' => 'Updated notes'])->assertOk();
    $this->deleteJson("/api/invoices/{$legacyId}")->assertOk();
});
