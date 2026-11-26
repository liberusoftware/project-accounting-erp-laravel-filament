<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AccountantWorkspaceApi\AccountantWorkspaceApiServiceProvider;
use Liberu\Accounting\AccountantWorkspaceApi\Policies\WorkspacePolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-accountant-workspace-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AccountantWorkspaceApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AccountantWorkspaceApiServiceProvider::class);

    $policy = new WorkspacePolicy();
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

    $storeRes = $this->postJson('/api/v1/accounting/accountant-workspace', [
        'subject_type' => 'client',
        'subject_id' => 'client-1',
        'name' => 'Q1 Close',
        'status' => 'active',
        'next_deadline' => '2026-04-15',
        'alerts' => [['message' => 'Pending review']],
        'notes' => [['text' => 'Initial note']],
        'requests' => [['title' => 'Bank statement']],
        'metadata' => ['priority' => 'high'],
    ]);
    expect($storeRes->status())->toBeLessThan(300);

    $indexRes = $this->getJson('/api/v1/accounting/accountant-workspace');
    expect($indexRes->status())->toBe(200);

    $filteredRes = $this->getJson('/api/v1/accounting/accountant-workspace?status=active&per_page=10');
    expect($filteredRes->status())->toBe(200);
});
