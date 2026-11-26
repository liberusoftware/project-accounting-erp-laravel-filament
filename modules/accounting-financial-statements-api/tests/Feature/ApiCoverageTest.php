<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FinancialStatementsApi\FinancialStatementsApiServiceProvider;
use Liberu\Accounting\FinancialStatementsApi\Policies\FinancialStatementsPolicy;

it('covers accounting-financial-statements-api provider, policy, and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(FinancialStatementsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(FinancialStatementsApiServiceProvider::class);

    $policy = new FinancialStatementsPolicy();
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
    expect($policy->view($allowUser))->toBeTrue();
    expect($policy->view($denyUser))->toBeFalse();
    expect($policy->view(null))->toBeFalse();

    $legalEntityId = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'Statements Corp',
        'registration_number' => 'STMT-001',
        'currency_code' => 'USD',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bookId = DB::table('accounting_books')->insertGetId([
        'legal_entity_id' => $legalEntityId,
        'name' => 'Main Book',
        'code' => 'MAIN',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $base = '/api/v1/accounting/financial-statements';
    expect($this->getJson("{$base}/profit-and-loss?book_id={$bookId}&start_date=2026-01-01&end_date=2026-12-31")->status())->toBe(200);
    expect($this->getJson("{$base}/balance-sheet?book_id={$bookId}&as_of_date=2026-12-31")->status())->toBe(200);
    expect($this->getJson("{$base}/cash-flow?book_id={$bookId}&start_date=2026-01-01&end_date=2026-12-31")->status())->toBe(200);
    expect($this->getJson("{$base}/changes-in-equity?book_id={$bookId}&start_date=2026-01-01&end_date=2026-12-31")->status())->toBe(200);
    expect($this->getJson("{$base}/comparative?book_id={$bookId}&start_date=2026-01-01&end_date=2026-12-31&comparative_start_date=2025-01-01&comparative_end_date=2025-12-31")->status())->toBe(200);
    expect($this->getJson("{$base}/drill-through?book_id={$bookId}&account_id=1&start_date=2026-01-01&end_date=2026-12-31")->status())->toBe(200);

    // Invalid book triggers InvalidStatementRequest -> 422
    expect($this->getJson("{$base}/profit-and-loss?book_id=999999&start_date=2026-01-01&end_date=2026-12-31")->status())->toBe(422);
});
