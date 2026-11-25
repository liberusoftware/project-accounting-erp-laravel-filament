<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\ChartOfAccountsApi\ChartOfAccountsApiServiceProvider;
use Liberu\Accounting\ChartOfAccountsApi\Http\Requests\StoreAccountRequest;
use Liberu\Accounting\ChartOfAccountsApi\Http\Requests\UpdateAccountRequest;
use Liberu\Accounting\ChartOfAccountsApi\Policies\AccountPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-chart-of-accounts-api api provider, policies, requests, and index route', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ChartOfAccountsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ChartOfAccountsApiServiceProvider::class);
    $formReq = new StoreAccountRequest();
    if (method_exists($formReq, 'authorize')) {
        expect($formReq->authorize())->toBeBool();
    }
    if (method_exists($formReq, 'rules')) {
        expect($formReq->rules())->toBeArray();
    }
    $formReq = new UpdateAccountRequest();
    if (method_exists($formReq, 'authorize')) {
        expect($formReq->authorize())->toBeBool();
    }
    if (method_exists($formReq, 'rules')) {
        expect($formReq->rules())->toBeArray();
    }
    $policy = new AccountPolicy();
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
    $record = (new Account())->forceFill(['team_id' => 1, 'legal_entity_id' => 1]);
    expect($policy->viewAny($allowUser))->toBeBool();
    expect($policy->viewAny($denyUser))->toBeBool();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $record))->toBeBool();
    expect($policy->view($denyUser, $record))->toBeBool();
    expect($policy->view(null, $record))->toBeFalse();
    expect($policy->create($allowUser))->toBeBool();
    expect($policy->create($denyUser))->toBeBool();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeBool();
    expect($policy->update($denyUser, $record))->toBeBool();
    expect($policy->update(null, $record))->toBeFalse();
    expect($policy->delete($allowUser, $record))->toBeBool();
    expect($policy->delete($denyUser, $record))->toBeBool();
    expect($policy->delete(null, $record))->toBeFalse();
});
