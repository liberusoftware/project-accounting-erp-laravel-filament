<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CarbonAccountingApi\CarbonAccountingApiServiceProvider;
use Liberu\Accounting\CarbonAccountingApi\Policies\CarbonAccountingPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-carbon-accounting-api api provider, policies, resources, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CarbonAccountingApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CarbonAccountingApiServiceProvider::class);

    $policy = new CarbonAccountingPolicy();
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
    $noTeamAllowUser = new class() extends User
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
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create($noTeamAllowUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();

    $this->postJson('/api/v1/accounting/carbon/activities', [
        'activity_date' => '2026-01-15',
        'scope' => '1',
        'category' => 'mobile-combustion',
        'description' => 'Fleet diesel usage',
        'quantity' => 100,
        'unit' => 'L',
        'emission_factor' => 2.68,
        'factor_source' => 'DEFRA 2026',
        'evidence' => ['invoice' => 'INV-101'],
        'is_estimate' => false,
        'metadata' => ['site' => 'HQ'],
    ])->assertSuccessful()
        ->assertJsonPath('data.type', 'accounting-carbon-activities')
        ->assertJsonPath('data.attributes.scope', '1');

    $this->getJson('/api/v1/accounting/carbon/activities')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/accounting/carbon/activities?scope=1&per_page=10')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $noTeamUser = User::factory()->create(['current_team_id' => null]);
    Sanctum::actingAs($noTeamUser, ['*']);

    $this->getJson('/api/v1/accounting/carbon/activities')
        ->assertForbidden();
});
