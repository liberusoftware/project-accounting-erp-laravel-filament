<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\TaxCore\Models\TaxRule;
use Liberu\Accounting\TaxCoreApi\Policies\TaxCorePolicy;
use Liberu\Accounting\TaxCoreApi\TaxCoreApiServiceProvider;

it('covers accounting-tax-core-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(TaxCoreApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(TaxCoreApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/tax-core', [
        'code' => 'UK-STD-20',
        'name' => 'UK Standard VAT 20%',
        'tax_type' => 'vat',
        'jurisdiction_code' => 'GB',
        'rate' => 20.0,
        'effective_from' => '2026-01-01',
    ]);
    $storeResponse->assertCreated();
    $ruleId = $storeResponse->json('data.id');

    $ruleModel = TaxRule::query()->findOrFail($ruleId);
    $policy = new TaxCorePolicy();
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
    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $ruleModel))->toBeTrue();
    expect($policy->view($denyUser, $ruleModel))->toBeFalse();
    expect($policy->view(null, $ruleModel))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $ruleModel))->toBeTrue();
    expect($policy->update($denyUser, $ruleModel))->toBeFalse();
    expect($policy->update(null, $ruleModel))->toBeFalse();
    expect($policy->delete($allowUser, $ruleModel))->toBeTrue();
    expect($policy->delete($denyUser, $ruleModel))->toBeFalse();
    expect($policy->delete(null, $ruleModel))->toBeFalse();

    $this->getJson('/api/v1/accounting/tax-core?status=draft')->assertOk();
    $this->getJson("/api/v1/accounting/tax-core/{$ruleId}")->assertOk();
    $this->patchJson("/api/v1/accounting/tax-core/{$ruleId}", ['name' => 'UK Standard VAT'])->assertOk();
    $this->postJson("/api/v1/accounting/tax-core/{$ruleId}/activate")->assertOk();
    $this->postJson("/api/v1/accounting/tax-core/{$ruleId}/archive")->assertOk();
});
