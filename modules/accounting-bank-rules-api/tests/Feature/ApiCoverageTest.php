<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\BankRules\Models\BankRule;
use Liberu\Accounting\BankRulesApi\BankRulesApiServiceProvider;
use Liberu\Accounting\BankRulesApi\Policies\BankRulesPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-bank-rules-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(BankRulesApiServiceProvider::class, force: true);
    $provider->boot();
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
    $wrongTeamUser = new class() extends User
    {
        public int $current_team_id = 2;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $ruleRecord = new BankRule(['team_id' => 1]);

    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $ruleRecord))->toBeTrue();
    expect($policy->view($wrongTeamUser, $ruleRecord))->toBeFalse();
    expect($policy->view($denyUser, $ruleRecord))->toBeFalse();
    expect($policy->view(null, $ruleRecord))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $ruleRecord))->toBeTrue();
    expect($policy->update($wrongTeamUser, $ruleRecord))->toBeFalse();
    expect($policy->update($denyUser, $ruleRecord))->toBeFalse();
    expect($policy->update(null, $ruleRecord))->toBeFalse();
    expect($policy->delete($allowUser, $ruleRecord))->toBeTrue();
    expect($policy->delete($wrongTeamUser, $ruleRecord))->toBeFalse();
    expect($policy->delete($denyUser, $ruleRecord))->toBeFalse();
    expect($policy->delete(null, $ruleRecord))->toBeFalse();

    $storeRes = $this->postJson('/api/v1/accounting/bank-rules', [
        'name' => 'Coffee Rule',
        'priority' => 10,
        'enabled' => true,
        'conditions' => ['description_contains' => 'Coffee'],
        'actions' => ['category' => 'meals'],
        'automation_mode' => 'suggest',
        'metadata' => ['owner' => 'ops'],
    ]);
    expect($storeRes->status())->toBeLessThan(300);
    $ruleId = $storeRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/bank-rules')->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/bank-rules/{$ruleId}")->status())->toBe(200);

    $updateRes = $this->patchJson("/api/v1/accounting/bank-rules/{$ruleId}", [
        'name' => 'Coffee Rule Updated',
        'priority' => 20,
        'enabled' => true,
        'conditions' => ['description_contains' => 'Espresso'],
        'actions' => ['category' => 'meals'],
        'automation_mode' => 'automatic',
    ]);
    expect($updateRes->status())->toBe(200);

    $testRes = $this->postJson("/api/v1/accounting/bank-rules/{$ruleId}/test", [
        'transaction' => ['description' => 'Espresso Bar', 'amount' => 6.50],
    ]);
    expect($testRes->status())->toBe(200);

    expect($this->getJson("/api/v1/accounting/bank-rules/{$ruleId}/history")->status())->toBe(200);
    expect($this->deleteJson("/api/v1/accounting/bank-rules/{$ruleId}")->status())->toBe(204);
});
