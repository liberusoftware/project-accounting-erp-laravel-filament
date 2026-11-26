<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\WithholdingTax\Enums\WithholdingStatus;
use Liberu\Accounting\WithholdingTax\Models\WithholdingTaxLiability;
use Liberu\Accounting\WithholdingTaxApi\WithholdingTaxApiServiceProvider;

it('covers accounting-withholding-tax-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(WithholdingTaxApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(WithholdingTaxApiServiceProvider::class);

    $ruleResponse = $this->postJson('/api/v1/accounting/withholding-tax/rules', [
        'code' => 'CIS-20',
        'name' => 'CIS Standard 20%',
        'jurisdiction' => 'GB',
        'rate' => 20.0,
        'threshold' => 0,
        'effective_from' => '2026-01-01',
    ]);
    $ruleResponse->assertCreated();
    $ruleId = $ruleResponse->json('id');

    $this->getJson('/api/v1/accounting/withholding-tax/rules')->assertOk();

    $deductionResponse = $this->postJson("/api/v1/accounting/withholding-tax/rules/{$ruleId}/deductions", [
        'party_type' => 'supplier',
        'party_id' => 'SUP-001',
        'source_ref' => 'BILL-001',
        'currency' => 'GBP',
        'gross_amount' => 1000.00,
    ]);
    $deductionResponse->assertCreated();
    $deductionId = $deductionResponse->json('id');

    $this->getJson('/api/v1/accounting/withholding-tax/deductions')->assertOk();

    $liability = WithholdingTaxLiability::query()->create([
        'team_id' => 1,
        'deduction_id' => $deductionId,
        'amount' => 200.00,
        'due_on' => '2026-03-19',
        'status' => WithholdingStatus::Calculated,
    ]);

    $this->postJson("/api/v1/accounting/withholding-tax/liabilities/{$liability->id}/remit", [
        'amount' => 200.00,
        'remitted_on' => '2026-03-15',
        'reference' => 'HMRC-CIS-001',
    ])->assertCreated();
});
