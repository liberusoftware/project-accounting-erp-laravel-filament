<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PayrollJournalsApi\PayrollJournalsApiServiceProvider;

it('covers accounting-payroll-journals-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(PayrollJournalsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(PayrollJournalsApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/payroll-journals', [
        'journal_ref' => 'PJ-2026-02',
        'payroll_period_start' => '2026-02-01',
        'payroll_period_end' => '2026-02-28',
        'currency' => 'GBP',
        'gross_pay' => 5000.00,
        'taxes' => 1000.00,
        'deductions' => 500.00,
        'benefits' => 200.00,
        'employer_costs' => 600.00,
        'net_pay' => 3500.00,
    ]);
    $storeResponse->assertCreated();
    $journalId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/payroll-journals')->assertOk();
    $this->getJson('/api/v1/accounting/payroll-journals/summary')->assertOk();
    $this->getJson("/api/v1/accounting/payroll-journals/{$journalId}")->assertOk();
    $this->postJson("/api/v1/accounting/payroll-journals/{$journalId}/post")->assertOk();
    $this->postJson("/api/v1/accounting/payroll-journals/{$journalId}/reverse", [
        'reversal_ref' => 'PJ-2026-02-REV',
    ])->assertOk();
});
