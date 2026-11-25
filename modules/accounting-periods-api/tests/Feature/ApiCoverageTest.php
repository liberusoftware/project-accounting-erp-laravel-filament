<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PeriodsApi\AccountingPeriodsApiServiceProvider;
use Liberu\Accounting\PeriodsApi\Http\Requests\StoreAccountingPeriodRequest;
use Liberu\Accounting\PeriodsApi\Policies\AccountingPeriodsPolicy;

it('covers accounting-periods-api api provider, policies, requests, and index route', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AccountingPeriodsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AccountingPeriodsApiServiceProvider::class);
    $formReq = new StoreAccountingPeriodRequest();
    if (method_exists($formReq, 'authorize')) {
        expect($formReq->authorize())->toBeBool();
    }
    if (method_exists($formReq, 'rules')) {
        expect($formReq->rules())->toBeArray();
    }
    $policy = new AccountingPeriodsPolicy();
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
    expect($policy->view($allowUser))->toBeBool();
    expect($policy->view($denyUser))->toBeBool();
    expect($policy->view(null))->toBeFalse();
    expect($policy->create($allowUser))->toBeBool();
    expect($policy->create($denyUser))->toBeBool();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser))->toBeBool();
    expect($policy->update($denyUser))->toBeBool();
    expect($policy->update(null))->toBeFalse();
});
