<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\BudgetsApi\BudgetsApiServiceProvider;
use Liberu\Accounting\BudgetsApi\Policies\BudgetsPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-budgets-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(BudgetsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(BudgetsApiServiceProvider::class);

    $policy = new BudgetsPolicy();
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
    $wrongTeamUser = new class() extends User
    {
        public int $current_team_id = 2;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $record = (object) ['team_id' => 1];

    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $record))->toBeTrue();
    expect($policy->view($wrongTeamUser, $record))->toBeFalse();
    expect($policy->view($denyUser, $record))->toBeFalse();
    expect($policy->view(null, $record))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeTrue();
    expect($policy->update($wrongTeamUser, $record))->toBeFalse();
    expect($policy->update($denyUser, $record))->toBeFalse();
    expect($policy->update(null, $record))->toBeFalse();

    $storeRes = $this->postJson('/api/v1/accounting/budgets', [
        'name' => 'FY2026 Operating Budget',
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'currency' => 'USD',
        'notes' => 'Initial annual budget',
        'metadata' => ['department' => 'engineering'],
    ]);
    expect($storeRes->status())->toBeLessThan(300);
    $budgetId = $storeRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/budgets')->status())->toBe(200);
    expect($this->getJson('/api/v1/accounting/budgets?status=draft&per_page=10')->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/budgets/{$budgetId}")->status())->toBe(200);

    $lineRes = $this->postJson("/api/v1/accounting/budgets/{$budgetId}/lines", [
        'account_id' => 5001,
        'project_id' => 1,
        'planned_amount' => 50000.00,
        'dimensions' => ['region' => 'NA'],
        'phases' => ['Q1' => 12500, 'Q2' => 12500, 'Q3' => 12500, 'Q4' => 12500],
        'notes' => 'Cloud infrastructure',
    ]);
    expect($lineRes->status())->toBe(200);

    expect($this->postJson("/api/v1/accounting/budgets/{$budgetId}/submit")->status())->toBe(200);
    expect($this->postJson("/api/v1/accounting/budgets/{$budgetId}/approve")->status())->toBe(200);
    expect($this->postJson("/api/v1/accounting/budgets/{$budgetId}/revise", [
        'notes' => 'Q2 reforecast',
        'metadata' => ['revision_reason' => 'headcount'],
    ])->status())->toBe(200);
});
