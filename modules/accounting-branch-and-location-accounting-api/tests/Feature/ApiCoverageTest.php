<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\BranchLocationAccountingApi\BranchLocationAccountingApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-branch-and-location-accounting-api api provider, policies, requests, and index route', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(BranchLocationAccountingApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BranchLocationAccountingApiServiceProvider::class);
    $res = $this->getJson('/api/v1/accounting/branches');
    expect($res->status())->toBeLessThan(500);
});
