<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CashCoding\Models\CashCodingBatch;
use Liberu\Accounting\CashCodingApi\CashCodingApiServiceProvider;
use Liberu\Accounting\CashCodingApi\Policies\CashCodingPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-cash-coding-api api provider, policies, resources, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CashCodingApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CashCodingApiServiceProvider::class);

    $policy = new CashCodingPolicy();
    $allowUser = new class() extends User
    {
        public int $current_team_id = 1;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $otherTeamUser = new class() extends User
    {
        public int $current_team_id = 2;

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
    $record = (new CashCodingBatch())->forceFill(['team_id' => 1]);
    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $record))->toBeTrue();
    expect($policy->view($otherTeamUser, $record))->toBeFalse();
    expect($policy->view($denyUser, $record))->toBeFalse();
    expect($policy->view(null, $record))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeTrue();
    expect($policy->update($otherTeamUser, $record))->toBeFalse();
    expect($policy->update($denyUser, $record))->toBeFalse();
    expect($policy->update(null, $record))->toBeFalse();

    $storeRes = $this->postJson('/api/v1/accounting/cash-coding', [
        'reference' => 'CCB-2026-001',
        'currency' => 'GBP',
        'lines' => [
            [
                'source_reference' => 'TX-1001',
                'amount' => 125.50,
                'currency' => 'GBP',
                'account_id' => 1001,
            ],
        ],
        'payee_creation_policy' => 'review',
        'metadata' => ['source' => 'bank-feed'],
    ])->assertSuccessful();

    $batchId = $storeRes->json('data.id');
    expect($batchId)->not->toBeNull();

    $this->getJson('/api/v1/accounting/cash-coding')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'accounting-cash-coding');

    $this->getJson("/api/v1/accounting/cash-coding/{$batchId}")
        ->assertOk()
        ->assertJsonPath('data.attributes.reference', 'CCB-2026-001');

    $this->postJson("/api/v1/accounting/cash-coding/{$batchId}/review")
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'in_review');

    $this->postJson("/api/v1/accounting/cash-coding/{$batchId}/post")
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'posted');

    $this->postJson("/api/v1/accounting/cash-coding/{$batchId}/undo", [
        'reason' => 'Duplicate bank feed batch',
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'undone');
});
