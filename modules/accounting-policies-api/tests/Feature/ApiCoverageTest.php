<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Policies\Models\PolicyRule;
use Liberu\Accounting\PoliciesApi\AccountingPoliciesApiServiceProvider;
use Liberu\Accounting\PoliciesApi\Policies\AccountingPoliciesPolicy;

it('covers accounting-policies-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AccountingPoliciesApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AccountingPoliciesApiServiceProvider::class);

    $entity = LegalEntity::query()->create([
        'name' => 'Policies Entity',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
    $book = Book::query()->create([
        'legal_entity_id' => $entity->id,
        'name' => 'Policy Book',
        'code' => 'POL',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);

    $storeResponse = $this->postJson('/api/v1/accounting/accounting-policies/policies', [
        'book_id' => $book->id,
        'category' => 'rounding',
        'key' => 'precision',
        'value' => ['decimals' => 2],
        'effective_from' => '2026-01-01',
        'effective_until' => '2026-12-31',
        'is_active' => true,
    ]);
    $storeResponse->assertCreated();
    $ruleId = $storeResponse->json('data.id');

    // Overlapping rule triggers InvalidPolicyRule catch branch in store()
    $this->postJson('/api/v1/accounting/accounting-policies/policies', [
        'book_id' => $book->id,
        'category' => 'rounding',
        'key' => 'precision',
        'value' => ['decimals' => 4],
        'effective_from' => '2026-06-01',
    ])->assertUnprocessable();

    $ruleModel = PolicyRule::query()->findOrFail($ruleId);
    $policy = new AccountingPoliciesPolicy();
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

    $this->getJson("/api/v1/accounting/accounting-policies/policies?book_id={$book->id}&category=rounding")->assertOk();
    $this->getJson("/api/v1/accounting/accounting-policies/policies/{$ruleId}")->assertOk();

    $this->patchJson("/api/v1/accounting/accounting-policies/policies/{$ruleId}", [
        'value' => ['decimals' => 3],
    ])->assertOk();

    // effective_until < effective_from triggers InvalidPolicyRule catch branch in update()
    $this->patchJson("/api/v1/accounting/accounting-policies/policies/{$ruleId}", [
        'effective_until' => '2025-01-01',
    ])->assertUnprocessable();

    $this->getJson("/api/v1/accounting/accounting-policies/resolve?book_id={$book->id}&category=rounding&key=precision&date=2026-06-15")->assertOk();
    $this->getJson("/api/v1/accounting/accounting-policies/resolve?book_id={$book->id}&category=rounding&key=missing&date=2026-06-15")->assertOk();

    $this->deleteJson("/api/v1/accounting/accounting-policies/policies/{$ruleId}")->assertNoContent();
});
