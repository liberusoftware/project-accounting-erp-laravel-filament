<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FinancialStatementsApi\FinancialStatementsApiServiceProvider;
use Liberu\Accounting\FinancialStatementsApi\Policies\FinancialStatementsPolicy;

it('covers accounting-financial-statements-api api provider, policies, requests, and index route', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(FinancialStatementsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(FinancialStatementsApiServiceProvider::class);
    $policy = new FinancialStatementsPolicy();
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
    expect($policy->view($allowUser))->toBeBool();
    expect($policy->view($denyUser))->toBeBool();
    expect($policy->view(null))->toBeFalse();
});
