<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\EstimatesAndQuotesApi\EstimatesAndQuotesApiServiceProvider;
use Liberu\Accounting\EstimatesAndQuotesApi\Http\Controllers\EstimatesController;

it('covers accounting-estimates-and-quotes-api provider, controllers, and resource', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(EstimatesAndQuotesApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(EstimatesAndQuotesApiServiceProvider::class);

    // EstimatesController (v1/accounting/estimates-and-quotes)
    $storeRes = $this->postJson('/api/v1/accounting/estimates-and-quotes', [
        'legal_entity_id' => 1,
        'customer_ref' => 'CUST-1',
        'quote_ref' => 'QT-100',
        'name' => 'ERP Implementation',
        'currency' => 'USD',
        'issue_date' => '2026-01-10',
        'expires_on' => '2026-12-31',
        'terms' => 'Net 30',
    ]);
    expect($storeRes->status())->toBeLessThan(500);
    $estimateId = $storeRes->json('data.id');
    expect($estimateId)->not->toBeNull();

    expect($this->getJson('/api/v1/accounting/estimates-and-quotes?legal_entity_id=1&status=draft')->status())->toBe(200);
    expect($this->getJson('/api/v1/accounting/estimates-and-quotes')->status())->toBe(200);
    expect($this->getJson('/api/v1/accounting/estimates-and-quotes?status=invalid-status')->status())->toBe(422);
    expect($this->getJson("/api/v1/accounting/estimates-and-quotes/{$estimateId}")->status())->toBe(200);

    expect($this->postJson("/api/v1/accounting/estimates-and-quotes/{$estimateId}/items", [
        'item_ref' => 'SRV-1',
        'description' => 'Consulting services',
        'quantity' => 5,
        'unit_price' => 200,
        'tax_rate' => 10,
    ])->status())->toBeLessThan(500);

    expect($this->postJson("/api/v1/accounting/estimates-and-quotes/{$estimateId}/send")->status())->toBe(200);
    expect($this->postJson("/api/v1/accounting/estimates-and-quotes/{$estimateId}/decide", [
        'accepted' => true,
        'reason' => 'Looks good',
    ])->status())->toBe(200);
    expect($this->postJson("/api/v1/accounting/estimates-and-quotes/{$estimateId}/convert", [
        'converted_ref' => 'INV-100',
    ])->status())->toBe(200);

    $expirableRes = $this->postJson('/api/v1/accounting/estimates-and-quotes', [
        'legal_entity_id' => 1,
        'customer_ref' => 'CUST-2',
        'quote_ref' => 'QT-101',
        'name' => 'Expired Quote',
        'currency' => 'USD',
        'issue_date' => '2024-01-01',
        'expires_on' => '2024-01-15',
    ]);
    $expirableId = $expirableRes->json('data.id');
    expect($this->postJson("/api/v1/accounting/estimates-and-quotes/{$expirableId}/expire")->status())->toBe(200);

    // EstimateController (api/v1/estimates)
    $customerId = DB::table('customers')->insertGetId([
        'team_id' => 1,
        'customer_name' => 'Jane',
        'customer_last_name' => 'Doe',
        'customer_address' => '123 Main St',
        'customer_email' => 'jane.estimate@example.test',
        'customer_phone' => '555-0101',
        'customer_city' => 'London',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $erpStore = $this->postJson('/api/v1/estimates', [
        'customer_id' => $customerId,
        'estimate_date' => '2026-01-10',
        'expiration_date' => '2026-02-10',
        'subtotal_amount' => 100,
        'tax_amount' => 20,
        'total_amount' => 120,
        'status' => 'draft',
        'notes' => 'Initial estimate',
        'terms' => 'Net 30',
    ]);
    expect($erpStore->status())->toBe(201);
    $erpEstimateId = $erpStore->json('estimate_id') ?? $erpStore->json('id');

    expect($this->getJson('/api/v1/estimates')->status())->toBe(200);
    expect($this->getJson("/api/v1/estimates/{$erpEstimateId}")->status())->toBe(200);
    expect($this->putJson("/api/v1/estimates/{$erpEstimateId}", [
        'customer_id' => $customerId,
        'total_amount' => 150,
        'notes' => 'Updated estimate',
    ])->status())->toBe(200);

    $otherUser = User::factory()->create(['current_team_id' => 2]);
    Sanctum::actingAs($otherUser, ['*']);
    expect($this->getJson("/api/v1/estimates/{$erpEstimateId}")->status())->toBe(403);

    Sanctum::actingAs($user, ['*']);
    expect($this->deleteJson("/api/v1/estimates/{$erpEstimateId}")->status())->toBe(200);
});
