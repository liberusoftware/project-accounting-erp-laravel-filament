<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\TimeTrackingApi\TimeTrackingApiServiceProvider;

it('covers accounting-time-tracking-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(TimeTrackingApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(TimeTrackingApiServiceProvider::class);

    $entryResponse = $this->postJson('/api/v1/accounting/time-tracking/entries', [
        'worker_ref' => 'EMP-001',
        'customer_ref' => 'CUST-001',
        'project_ref' => 'PRJ-001',
        'task_ref' => 'TASK-001',
        'worked_on' => '2026-02-10',
        'hours' => 4.5,
        'billable_rate' => 120.00,
        'cost_rate' => 60.00,
        'currency' => 'GBP',
        'billable' => true,
        'description' => 'API development',
    ]);
    $entryResponse->assertCreated();
    $entryId = $entryResponse->json('id');

    $this->getJson('/api/v1/accounting/time-tracking/entries')->assertOk();
    $this->postJson("/api/v1/accounting/time-tracking/entries/{$entryId}/submit")->assertOk();
    $this->postJson("/api/v1/accounting/time-tracking/entries/{$entryId}/approve")->assertOk();

    $timerResponse = $this->postJson('/api/v1/accounting/time-tracking/timers/start', [
        'worker_ref' => 'EMP-001',
        'project_ref' => 'PRJ-001',
        'started_at' => now()->subHour()->toISOString(),
    ]);
    $timerResponse->assertCreated();
    $timerId = $timerResponse->json('id');

    $this->postJson("/api/v1/accounting/time-tracking/timers/{$timerId}/stop")->assertOk();
});
