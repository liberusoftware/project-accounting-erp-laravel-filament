<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ProjectProfitabilityApi\ProjectProfitabilityApiServiceProvider;
use Liberu\Accounting\ProjectsAndJobs\Enums\ProjectStatus;
use Liberu\Accounting\ProjectsAndJobs\Models\ProjectJob;

it('covers accounting-project-profitability-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ProjectProfitabilityApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ProjectProfitabilityApiServiceProvider::class);

    $job = ProjectJob::query()->create([
        'team_id' => 1,
        'name' => 'Cloud Migration',
        'code' => 'PRJ-PROF-1',
        'status' => ProjectStatus::Active,
        'budget_currency' => 'GBP',
    ]);

    $storeResponse = $this->postJson('/api/v1/accounting/project-profitability', [
        'project_job_id' => $job->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'currency' => 'GBP',
        'revenue_amount' => 10000.00,
        'cost_amount' => 6000.00,
    ]);
    $storeResponse->assertCreated();
    $profitabilityId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/project-profitability')->assertOk();
    $this->getJson("/api/v1/accounting/project-profitability/{$profitabilityId}")->assertOk();
    $this->postJson("/api/v1/accounting/project-profitability/{$profitabilityId}/finalize")->assertOk();
    $this->getJson("/api/v1/accounting/project-profitability/project/{$job->id}/dashboard")->assertOk();
});
