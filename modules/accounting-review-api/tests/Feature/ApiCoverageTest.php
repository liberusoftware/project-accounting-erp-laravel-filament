<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ReviewApi\Policies\ReviewPolicy;
use Liberu\Accounting\ReviewApi\ReviewApiServiceProvider;

it('covers accounting-review-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ReviewApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ReviewApiServiceProvider::class);

    $policy = new ReviewPolicy();
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
    $record = (object) ['team_id' => 1];
    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->view($allowUser, $record))->toBeTrue();
    expect($policy->view($denyUser, $record))->toBeFalse();
    expect($policy->view(null, $record))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeTrue();
    expect($policy->update($denyUser, $record))->toBeFalse();
    expect($policy->update(null, $record))->toBeFalse();

    $storeResponse = $this->postJson('/api/v1/accounting/review', [
        'item_type' => 'variance',
        'source_type' => 'journal',
        'source_id' => 'JRN-001',
        'severity' => 'medium',
        'title' => 'Unusual journal variance',
        'due_at' => '2026-03-31',
    ]);
    $storeResponse->assertSuccessful();
    $itemId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/review')->assertOk();

    $this->postJson("/api/v1/accounting/review/{$itemId}/resolve", [
        'summary' => 'Verified against supplier statement',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/review/{$itemId}/sign-off", [
        'attestation' => 'Reviewed and signed off',
    ])->assertOk();
});
