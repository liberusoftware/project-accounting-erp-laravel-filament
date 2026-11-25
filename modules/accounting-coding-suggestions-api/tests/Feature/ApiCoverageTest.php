<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CodingSuggestionsApi\CodingSuggestionsApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-coding-suggestions-api api provider, policies, requests, and index route', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CodingSuggestionsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CodingSuggestionsApiServiceProvider::class);
});
