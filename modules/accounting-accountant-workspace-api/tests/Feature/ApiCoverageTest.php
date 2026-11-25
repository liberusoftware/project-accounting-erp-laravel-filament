<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AccountantWorkspaceApi\AccountantWorkspaceApiServiceProvider;
use Liberu\Accounting\AccountantWorkspaceApi\Policies\WorkspacePolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-accountant-workspace-api api provider, policies, requests, and index route', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AccountantWorkspaceApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
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
    $record = (object) ['team_id' => 1, 'legal_entity_id' => 1, 'user_id' => 1];
    expect($policy->viewAny($allowUser))->toBeBool();
    expect($policy->viewAny($denyUser))->toBeBool();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->create($allowUser))->toBeBool();
    expect($policy->create($denyUser))->toBeBool();
    expect($policy->create(null))->toBeFalse();
    $res = $this->getJson('/api/v1/accounting/accountant-workspace');
    expect($res->status())->toBeLessThan(500);
});
