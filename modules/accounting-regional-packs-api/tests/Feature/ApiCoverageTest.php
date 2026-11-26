<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\RegionalPacks\Enums\RegionalArtifactType;
use Liberu\Accounting\RegionalPacksApi\RegionalPacksApiServiceProvider;

it('covers accounting-regional-packs-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(RegionalPacksApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(RegionalPacksApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/regional-packs', [
        'country_code' => 'GB',
        'locale' => 'en_GB',
        'currency' => 'GBP',
        'version' => '1.0.0',
        'effective_from' => '2026-01-01',
    ]);
    $storeResponse->assertSuccessful();
    $packId = $storeResponse->json('id');

    $this->getJson('/api/v1/accounting/regional-packs')->assertOk();
    $this->getJson("/api/v1/accounting/regional-packs/{$packId}")->assertOk();

    $artifacts = array_map(
        fn (RegionalArtifactType $type): array => [
            'type' => $type->value,
            'key' => 'default_'.$type->value,
            'definition' => ['enabled' => true],
        ],
        RegionalArtifactType::cases(),
    );

    $this->postJson("/api/v1/accounting/regional-packs/{$packId}/publish", [
        'artifacts' => $artifacts,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/regional-packs/{$packId}/compliance-tests")->assertOk();
});
