<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AssetEventsApi\AssetEventsApiServiceProvider;
use Liberu\Accounting\AssetEventsApi\Policies\AssetEventPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-asset-events-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AssetEventsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AssetEventsApiServiceProvider::class);

    $policy = new AssetEventPolicy();
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
    $noTeamUser = new class() extends User
    {
        public ?int $current_team_id = null;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };

    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($noTeamUser))->toBeFalse();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();

    $storeRes = $this->postJson('/api/v1/accounting/asset-events', [
        'asset_id' => 42,
        'event_type' => 'revaluation',
        'event_date' => '2026-01-15',
        'description' => 'Annual valuation adjustment',
        'source_type' => 'manual',
        'source_id' => 'evt-1',
        'old_values' => ['value' => 10000],
        'new_values' => ['value' => 12000],
        'evidence' => [['document' => 'appraisal.pdf']],
    ]);
    expect($storeRes->status())->toBeLessThan(300);

    expect($this->getJson('/api/v1/accounting/asset-events')->status())->toBe(200);
    expect($this->getJson('/api/v1/accounting/asset-events?asset_id=42&per_page=10')->status())->toBe(200);
});
