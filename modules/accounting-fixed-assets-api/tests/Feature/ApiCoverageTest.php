<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FixedAssetsApi\FixedAssetsApiServiceProvider;

it('covers accounting-fixed-assets-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(FixedAssetsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(FixedAssetsApiServiceProvider::class);

    $base = '/api/v1/accounting/fixed-assets';

    $catRes = $this->postJson("{$base}/categories", [
        'category_ref' => 'CAT-IT',
        'name' => 'IT Equipment',
        'asset_account_ref' => '1500',
        'depreciation_account_ref' => '6100',
        'useful_life_months' => 36,
        'depreciation_method' => 'straight_line',
    ]);
    expect($catRes->status())->toBe(201);
    $categoryId = (int) $catRes->json('data.id');

    $locRes = $this->postJson("{$base}/locations", [
        'location_ref' => 'LOC-HQ',
        'name' => 'Headquarters',
    ]);
    expect($locRes->status())->toBe(201);
    $locationId = (int) $locRes->json('data.id');

    $custRes = $this->postJson("{$base}/custodians", [
        'custodian_ref' => 'CUST-01',
        'name' => 'Alex Smith',
        'email' => 'alex@example.test',
    ]);
    expect($custRes->status())->toBe(201);
    $custodianId = (int) $custRes->json('data.id');

    $assetRes = $this->postJson($base, [
        'category_id' => $categoryId,
        'asset_ref' => 'FA-100',
        'name' => 'MacBook Pro',
        'acquired_on' => '2026-01-10',
        'cost' => 2500,
        'salvage_value' => 200,
        'currency' => 'USD',
    ]);
    expect($assetRes->status())->toBeLessThan(500);
    $assetId = $assetRes->json('data.id');
    expect($assetId)->not->toBeNull();

    expect($this->getJson($base)->status())->toBe(200);
    expect($this->getJson("{$base}?status=acquired")->status())->toBe(200);
    expect($this->getJson("{$base}?status=invalid-status")->status())->toBe(422);

    expect($this->patchJson("{$base}/{$assetId}", ['name' => 'MacBook Pro M4'])->status())->toBe(200);
    expect($this->postJson("{$base}/{$assetId}/location", ['location_id' => $locationId])->status())->toBe(200);
    expect($this->postJson("{$base}/{$assetId}/custodian", ['custodian_id' => $custodianId])->status())->toBe(200);

    expect($this->postJson("{$base}/{$assetId}/components", [
        'component_ref' => 'COMP-1',
        'name' => 'External Display',
        'cost' => 500,
        'useful_life_months' => 36,
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/{$assetId}/documents", [
        'document_ref' => 'DOC-1',
        'kind' => 'invoice',
        'file_ref' => 's3://docs/fa-100.pdf',
        'description' => 'Purchase invoice',
        'checksum' => 'abc123',
    ])->status())->toBe(200);

    expect($this->postJson("{$base}/{$assetId}/capitalize", ['book_ref' => 'STATUTORY'])->status())->toBe(200);
    expect($this->getJson("{$base}/{$assetId}")->status())->toBe(200);
    expect($this->getJson("{$base}/{$assetId}/summary")->status())->toBe(200);

    // Cross-team & no-team guards
    $otherTeamUser = User::factory()->create(['current_team_id' => 2]);
    Sanctum::actingAs($otherTeamUser, ['*']);
    expect($this->getJson("{$base}/{$assetId}")->status())->toBe(404);

    $noTeamUser = User::factory()->create(['current_team_id' => null]);
    Sanctum::actingAs($noTeamUser, ['*']);
    expect($this->getJson($base)->status())->toBe(403);

    Sanctum::actingAs($user, ['*']);
    expect($this->postJson("{$base}/{$assetId}/dispose", ['reason' => 'Sold'])->status())->toBe(200);
    expect($this->deleteJson("{$base}/{$assetId}")->status())->toBe(204);
});
