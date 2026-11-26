<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ProjectBillingApi\ProjectBillingApiServiceProvider;
use Liberu\Accounting\ProjectsAndJobs\Enums\ProjectStatus;
use Liberu\Accounting\ProjectsAndJobs\Models\ProjectJob;

it('covers accounting-project-billing-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ProjectBillingApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ProjectBillingApiServiceProvider::class);

    $job = ProjectJob::query()->create([
        'team_id' => 1,
        'name' => 'Website Redesign',
        'code' => 'PRJ-001',
        'status' => ProjectStatus::Active,
        'budget_currency' => 'GBP',
    ]);

    $storeResponse = $this->postJson('/api/v1/accounting/project-billing', [
        'project_job_id' => $job->id,
        'method' => 'fixed_fee',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'currency' => 'GBP',
        'amount' => 2500.00,
    ]);
    $storeResponse->assertCreated();
    $billingId = $storeResponse->json('id');

    $this->getJson('/api/v1/accounting/project-billing')->assertOk();
    $this->getJson("/api/v1/accounting/project-billing/{$billingId}")->assertOk();
    $this->postJson("/api/v1/accounting/project-billing/{$billingId}/handoff", [
        'invoice_ref' => 'INV-PRJ-001',
    ])->assertOk();
    $this->getJson("/api/v1/accounting/project-billing/project/{$job->id}/summary")->assertOk();
});
