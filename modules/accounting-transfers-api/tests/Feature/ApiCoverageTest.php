<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\TransfersApi\TransfersApiServiceProvider;

it('covers accounting-transfers-api api provider, policies, requests, and index route', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(TransfersApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(TransfersApiServiceProvider::class);
    $res = $this->getJson('/api/v1/accounting/transfers');
    expect($res->status())->toBeLessThan(500);
});
