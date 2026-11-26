<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\BusinessInsights\Models\InsightSnapshot;
use Liberu\Accounting\BusinessInsightsApi\BusinessInsightsApiServiceProvider;
use Liberu\Accounting\BusinessInsightsApi\Policies\BusinessInsightsPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-business-insights-api api provider, policies, resources, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(BusinessInsightsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BusinessInsightsApiServiceProvider::class);

    $policy = new BusinessInsightsPolicy();
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
    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();

    InsightSnapshot::create([
        'team_id' => 1,
        'metric' => 'gross_margin',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'value' => '42.5000',
        'comparison_value' => '40.0000',
        'unit' => 'percent',
        'explanation' => 'Gross margin improved over prior period.',
        'metadata' => ['source' => 'gl'],
        'refreshed_at' => now(),
    ]);

    $this->getJson('/api/v1/accounting/business-insights')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'accounting-insights')
        ->assertJsonPath('data.0.attributes.metric', 'gross_margin');

    $this->getJson('/api/v1/accounting/business-insights?metric=gross_margin&per_page=10')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $noTeamUser = User::factory()->create(['current_team_id' => null]);
    Sanctum::actingAs($noTeamUser, ['*']);

    $this->getJson('/api/v1/accounting/business-insights')
        ->assertForbidden();
});
