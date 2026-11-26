<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\VatApi\VatApiServiceProvider;

it('covers accounting-vat-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(VatApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(VatApiServiceProvider::class);

    $recordResponse = $this->postJson('/api/v1/accounting/vat/records', [
        'direction' => 'output',
        'tax_code' => 'STD20',
        'net_amount' => 1000.00,
        'tax_amount' => 200.00,
        'tax_rate' => 20.0,
        'box' => 1,
        'occurred_on' => '2026-02-10',
    ]);
    $recordResponse->assertCreated();
    $recordId = $recordResponse->json('id');

    $this->getJson('/api/v1/accounting/vat/records')->assertOk();
    $this->postJson("/api/v1/accounting/vat/records/{$recordId}/digital-evidence", [
        'payload' => ['source' => 'invoice', 'hash' => 'abc123'],
    ])->assertCreated();

    $returnResponse = $this->postJson('/api/v1/accounting/vat/returns', [
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'scheme' => 'standard',
    ]);
    $returnResponse->assertCreated();
    $returnId = $returnResponse->json('id');

    $this->getJson('/api/v1/accounting/vat/returns')->assertOk();
    $this->postJson("/api/v1/accounting/vat/returns/{$returnId}/adjustments", [
        'box' => 1,
        'amount' => 15.00,
        'reason' => 'Rounding adjustment',
    ])->assertCreated();
    $this->postJson("/api/v1/accounting/vat/returns/{$returnId}/submit", [
        'submission_ref' => 'MTD-2026-Q1',
    ])->assertOk();
});
