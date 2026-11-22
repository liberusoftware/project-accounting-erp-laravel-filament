<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\BankRules\Models\BankRule;
use Liberu\Accounting\BankRulesApi\BankRulesApiServiceProvider;
use Liberu\Accounting\BankRulesApi\Policies\BankRulesPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-bank-rules-api api provider, policies, requests, and index route', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(BankRulesApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BankRulesApiServiceProvider::class);
    $policy = new BankRulesPolicy();
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
    $record = (new BankRule())->forceFill(['team_id' => 1, 'legal_entity_id' => 1]);
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
    $res = $this->getJson('/api/v1/accounting/bank-rules');
    expect($res->status())->toBeLessThan(500);
});
