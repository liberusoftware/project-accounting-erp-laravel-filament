<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\ChartOfAccountsApi\ChartOfAccountsApiServiceProvider;
use Liberu\Accounting\ChartOfAccountsApi\Http\Requests\StoreAccountRequest;
use Liberu\Accounting\ChartOfAccountsApi\Http\Requests\UpdateAccountRequest;
use Liberu\Accounting\ChartOfAccountsApi\Policies\AccountPolicy;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\Account as ErpAccount;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-chart-of-accounts-api provider, policies, requests, resources, and all routes', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ChartOfAccountsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ChartOfAccountsApiServiceProvider::class);

    $storeReq = new StoreAccountRequest();
    expect($storeReq->authorize())->toBeFalse();
    $storeReq->setUserResolver(fn () => $user);
    expect($storeReq->authorize())->toBeTrue();
    expect($storeReq->rules())->toBeArray();

    $updateReq = new UpdateAccountRequest();
    expect($updateReq->authorize())->toBeFalse();
    $updateReq->setUserResolver(fn () => $user);
    expect($updateReq->authorize())->toBeTrue();
    expect($updateReq->rules())->toBeArray();

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
    $record = (new Account())->forceFill(['legal_entity_id' => 1]);
    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $record))->toBeTrue();
    expect($policy->view($denyUser, $record))->toBeFalse();
    expect($policy->view(null, $record))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeTrue();
    expect($policy->update($denyUser, $record))->toBeFalse();
    expect($policy->update(null, $record))->toBeFalse();
    expect($policy->delete($allowUser, $record))->toBeTrue();
    expect($policy->delete($denyUser, $record))->toBeFalse();
    expect($policy->delete(null, $record))->toBeFalse();

    $entity = LegalEntity::create([
        'name' => 'Acme UK Ltd',
        'registration_number' => 'REG-COA-001',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
    $otherEntity = LegalEntity::create([
        'name' => 'Acme US Inc',
        'registration_number' => 'REG-COA-002',
        'currency_code' => 'USD',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);

    $parentRes = $this->postJson('/api/v1/accounting/chart-of-accounts/accounts', [
        'legal_entity_id' => $entity->id,
        'code' => '1000',
        'name' => 'Cash & Bank',
        'description' => 'Cash control account',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_control_account' => false,
        'allow_manual_entry' => true,
        'locale' => 'en_GB',
        'metadata' => ['category' => 'current'],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'accounting-chart-account');

    $parentId = (int) $parentRes->json('data.id');

    $childRes = $this->postJson('/api/v1/accounting/chart-of-accounts/accounts', [
        'legal_entity_id' => $entity->id,
        'parent_id' => $parentId,
        'code' => '1010',
        'name' => 'Petty Cash',
        'type' => 'asset',
    ])->assertCreated();

    $childId = (int) $childRes->json('data.id');

    // Trigger InvalidAccount (duplicate code) and InvalidAccountHierarchy (cross-entity parent) in store()
    $this->postJson('/api/v1/accounting/chart-of-accounts/accounts', [
        'legal_entity_id' => $entity->id,
        'code' => '1000',
        'name' => 'Duplicate Cash',
        'type' => 'asset',
    ])->assertUnprocessable();

    $this->postJson('/api/v1/accounting/chart-of-accounts/accounts', [
        'legal_entity_id' => $otherEntity->id,
        'parent_id' => $parentId,
        'code' => '1000',
        'name' => 'Cross Entity Child',
        'type' => 'asset',
    ])->assertUnprocessable();

    $this->getJson("/api/v1/accounting/chart-of-accounts/accounts?legal_entity_id={$entity->id}&type=asset")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/accounting/chart-of-accounts/accounts?include_archived=1')
        ->assertOk();

    $this->getJson('/api/v1/accounting/chart-of-accounts/accounts/tree')
        ->assertUnprocessable();

    $this->getJson("/api/v1/accounting/chart-of-accounts/accounts/tree?legal_entity_id={$entity->id}")
        ->assertOk()
        ->assertJsonPath('data.0.attributes.code', '1000');

    $this->getJson("/api/v1/accounting/chart-of-accounts/accounts/{$parentId}")
        ->assertOk()
        ->assertJsonPath('data.attributes.code', '1000');

    $this->patchJson("/api/v1/accounting/chart-of-accounts/accounts/{$childId}", [
        'name' => 'Updated Petty Cash',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Updated Petty Cash');

    // Trigger InvalidAccountHierarchy (self parent) and InvalidAccount (duplicate code) in update()
    $this->patchJson("/api/v1/accounting/chart-of-accounts/accounts/{$parentId}", [
        'parent_id' => $parentId,
    ])->assertUnprocessable();

    $this->patchJson("/api/v1/accounting/chart-of-accounts/accounts/{$childId}", [
        'code' => '1000',
    ])->assertUnprocessable();

    // Trigger InvalidAccountHierarchy (archiving parent with active child) in destroy()
    $this->deleteJson("/api/v1/accounting/chart-of-accounts/accounts/{$parentId}")
        ->assertUnprocessable();

    $this->deleteJson("/api/v1/accounting/chart-of-accounts/accounts/{$childId}")
        ->assertNoContent();

    $this->deleteJson("/api/v1/accounting/chart-of-accounts/accounts/{$parentId}")
        ->assertNoContent();

    // Cover ChartOfAccountController (/api/v1/chart-of-accounts)
    $erpRes = $this->postJson('/api/v1/chart-of-accounts', [
        'account_number' => 1100,
        'account_name' => 'Operating Cash',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'opening_balance' => 1000,
        'description' => 'Primary operating account',
        'is_active' => true,
    ])->assertCreated();

    $erpAccountId = $erpRes->json('id');
    expect($erpAccountId)->not->toBeNull();

    $this->getJson('/api/v1/chart-of-accounts')
        ->assertOk();

    $this->getJson("/api/v1/chart-of-accounts/{$erpAccountId}")
        ->assertOk()
        ->assertJsonPath('account_name', 'Operating Cash');

    $this->putJson("/api/v1/chart-of-accounts/{$erpAccountId}", [
        'account_name' => 'Updated Operating Cash',
        'opening_balance' => 1500,
    ])->assertOk()
        ->assertJsonPath('account_name', 'Updated Operating Cash');

    // Cover authorizeOwner 403 branch for another team's ERP account
    $otherTeamAccount = ErpAccount::create([
        'account_number' => 1200,
        'account_name' => 'Other Team Cash',
        'account_type' => 'asset',
        'team_id' => 2,
    ]);

    $this->getJson("/api/v1/chart-of-accounts/{$otherTeamAccount->id}")
        ->assertForbidden();

    $this->deleteJson("/api/v1/chart-of-accounts/{$erpAccountId}")
        ->assertOk()
        ->assertJsonPath('deleted', true);
});
