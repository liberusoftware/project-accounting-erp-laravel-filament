<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ProjectCostingApi\ProjectCostingApiServiceProvider;
use Liberu\Accounting\ProjectsAndJobs\Enums\ProjectStatus;
use Liberu\Accounting\ProjectsAndJobs\Models\ProjectJob;

it('covers accounting-project-costing-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ProjectCostingApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ProjectCostingApiServiceProvider::class);

    $job = ProjectJob::query()->create([
        'team_id' => 1,
        'name' => 'ERP Rollout',
        'code' => 'PRJ-002',
        'status' => ProjectStatus::Active,
        'budget_currency' => 'GBP',
    ]);

    $storeResponse = $this->postJson('/api/v1/accounting/project-costing', [
        'project_job_id' => $job->id,
        'type' => 'labor',
        'occurred_on' => '2026-02-15',
        'amount' => 1200.00,
        'currency' => 'GBP',
        'actual' => true,
    ]);
    $storeResponse->assertCreated();
    $costId = $storeResponse->json('id');

    $this->getJson('/api/v1/accounting/project-costing')->assertOk();
    $this->getJson("/api/v1/accounting/project-costing/{$costId}")->assertOk();
    $this->getJson("/api/v1/accounting/project-costing/project/{$job->id}/summary")->assertOk();
});
