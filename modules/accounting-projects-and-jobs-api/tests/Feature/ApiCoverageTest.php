<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ProjectsAndJobsApi\ProjectsAndJobsApiServiceProvider;

it('covers accounting-projects-and-jobs-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ProjectsAndJobsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ProjectsAndJobsApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/projects-and-jobs', [
        'name' => 'Platform Modernization',
        'code' => 'PRJ-MOD-1',
        'start_date' => '2026-01-01',
        'end_date' => '2026-06-30',
        'budget_amount' => 50000.00,
        'budget_currency' => 'GBP',
    ]);
    $storeResponse->assertCreated();
    $projectId = $storeResponse->json('id');

    $this->getJson('/api/v1/accounting/projects-and-jobs')->assertOk();
    $this->getJson("/api/v1/accounting/projects-and-jobs/{$projectId}")->assertOk();
    $this->postJson("/api/v1/accounting/projects-and-jobs/{$projectId}/transition", [
        'status' => 'active',
    ])->assertOk();
});
