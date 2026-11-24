<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\RegionalPacks\Actions\CreateRegionalPack;
use Liberu\Accounting\RegionalPacks\Actions\PublishRegionalPack;
use Liberu\Accounting\RegionalPacks\Actions\RunComplianceTests;
use Liberu\Accounting\RegionalPacks\Enums\RegionalArtifactType;
use Liberu\Accounting\RegionalPacks\Enums\RegionalPackStatus;
use Liberu\Accounting\RegionalPacks\Exceptions\InvalidRegionalPack;
use Liberu\Accounting\RegionalPacks\Models\RegionalPack;
use Liberu\Accounting\RegionalPacks\Models\RegionalPackArtifact;
use Liberu\Accounting\RegionalPacks\RegionalPacksServiceProvider;

it('registers the regional packs service provider and runs migrations', function (): void {
    expect(app()->getProvider(RegionalPacksServiceProvider::class))
        ->toBeInstanceOf(RegionalPacksServiceProvider::class);

    expect(Schema::hasTable('accounting_regional_packs'))->toBeTrue()
        ->and(Schema::hasTable('accounting_regional_pack_artifacts'))->toBeTrue();

    expect(Schema::hasColumns('accounting_regional_packs', [
        'id', 'country_code', 'locale', 'currency', 'version', 'status', 'effective_from', 'effective_to', 'metadata',
    ]))->toBeTrue();

    expect(Schema::hasColumns('accounting_regional_pack_artifacts', [
        'id', 'pack_id', 'type', 'key', 'definition', 'status', 'test_results', 'metadata',
    ]))->toBeTrue();
});

it('exposes all regional pack enum cases', function (): void {
    expect(array_column(RegionalPackStatus::cases(), 'value'))
        ->toBe(['draft', 'active', 'retired']);

    expect(array_column(RegionalArtifactType::cases(), 'value'))
        ->toBe(['tax', 'report', 'document_format', 'filing_calendar', 'account_template', 'terminology', 'compliance_test']);
});

it('creates regional packs and validates ISO country code, locale, and currency', function (): void {
    $create = new CreateRegionalPack();

    $pack = $create->handle([
        'country_code' => 'gb',
        'locale' => 'en_GB',
        'currency' => 'gbp',
        'version' => '2026.1',
        'effective_from' => '2026-04-06',
        'metadata' => ['authority' => 'HMRC'],
    ]);

    expect($pack)->toBeInstanceOf(RegionalPack::class)
        ->and($pack->country_code)->toBe('GB')
        ->and($pack->currency)->toBe('GBP')
        ->and($pack->version)->toBe('2026.1')
        ->and($pack->status)->toBe(RegionalPackStatus::Draft)
        ->and($pack->effective_from->toDateString())->toBe('2026-04-06')
        ->and($pack->metadata)->toBe(['authority' => 'HMRC']);

    // Default version and effective_from
    $defaultPack = $create->handle([
        'country_code' => 'DE',
        'locale' => 'de_DE',
        'currency' => 'EUR',
    ]);
    expect($defaultPack->version)->toBe('1.0.0')
        ->and($defaultPack->effective_from)->not->toBeNull();

    foreach (['', 'U', 'GBR', '12'] as $badCountry) {
        expect(fn () => $create->handle(['country_code' => $badCountry, 'locale' => 'en_GB', 'currency' => 'GBP']))
            ->toThrow(InvalidRegionalPack::class, 'country_code must be an ISO 3166-1 alpha-2 code.');
    }

    expect(fn () => $create->handle(['country_code' => 'US', 'locale' => '', 'currency' => 'USD']))
        ->toThrow(InvalidRegionalPack::class, 'locale is required.');

    expect(fn () => $create->handle(['country_code' => 'US', 'locale' => 'en_US', 'currency' => '']))
        ->toThrow(InvalidRegionalPack::class, 'currency is required.');
});

it('publishes regional packs with all required artifact types and rejects incomplete or invalid artifacts', function (): void {
    $pack = (new CreateRegionalPack())->handle([
        'country_code' => 'FR',
        'locale' => 'fr_FR',
        'currency' => 'EUR',
    ]);

    $publish = new PublishRegionalPack();

    // Missing artifact types
    expect(fn () => $publish->handle($pack, [
        ['type' => 'tax', 'key' => 'vat-20', 'definition' => ['rate' => 20]],
    ]))->toThrow(InvalidRegionalPack::class, 'A regional pack must define every supported artifact type.');

    $allArtifacts = array_map(fn (RegionalArtifactType $type): array => [
        'type' => $type->value,
        'key' => $type->value.'-default',
        'definition' => ['rule' => $type->value],
        'metadata' => ['source' => 'statutory'],
    ], RegionalArtifactType::cases());

    // Invalid extra artifact (blank key)
    $withBadArtifact = [...$allArtifacts, ['type' => 'tax', 'key' => '', 'definition' => []]];
    expect(fn () => $publish->handle($pack, $withBadArtifact))
        ->toThrow(InvalidRegionalPack::class, 'Invalid regional artifact definition.');

    // Invalid extra artifact (unknown type)
    $withUnknownType = [...$allArtifacts, ['type' => 'unknown_type', 'key' => 'x', 'definition' => []]];
    expect(fn () => $publish->handle($pack, $withUnknownType))
        ->toThrow(InvalidRegionalPack::class, 'Invalid regional artifact definition.');

    // Invalid extra artifact (non-array definition)
    $withBadDef = [...$allArtifacts, ['type' => 'tax', 'key' => 'x', 'definition' => 'invalid']];
    expect(fn () => $publish->handle($pack, $withBadDef))
        ->toThrow(InvalidRegionalPack::class, 'Invalid regional artifact definition.');

    $published = $publish->handle($pack, $allArtifacts);
    expect($published->status)->toBe(RegionalPackStatus::Active)
        ->and($published->artifacts)->toHaveCount(count(RegionalArtifactType::cases()));

    $taxArtifact = $published->artifacts->firstWhere('type', RegionalArtifactType::Tax);
    expect($taxArtifact)->toBeInstanceOf(RegionalPackArtifact::class)
        ->and($taxArtifact->status)->toBe('active')
        ->and($taxArtifact->metadata)->toBe(['source' => 'statutory']);

    // Re-publishing updates existing artifacts in place
    $allArtifacts[0]['definition'] = ['rate' => 20.0];
    $republished = $publish->handle($published, $allArtifacts);
    expect($republished->artifacts()->count())->toBe(count(RegionalArtifactType::cases()))
        ->and($republished->artifacts()->where('type', RegionalArtifactType::Tax)->first()?->definition)->toBe(['rate' => 20.0]);
});

it('runs compliance tests and records passed and failed results', function (): void {
    $pack = (new CreateRegionalPack())->handle([
        'country_code' => 'CA',
        'locale' => 'en_CA',
        'currency' => 'CAD',
    ]);

    $artifacts = array_map(fn (RegionalArtifactType $type): array => [
        'type' => $type->value,
        'key' => $type->value.'-base',
        'definition' => $type === RegionalArtifactType::ComplianceTest
            ? ['expected' => 113.0, 'actual' => 113.0]
            : ['enabled' => true],
    ], RegionalArtifactType::cases());

    $artifacts[] = [
        'type' => RegionalArtifactType::ComplianceTest->value,
        'key' => 'compliance_test-mismatch',
        'definition' => ['expected' => 105.0, 'actual' => 100.0],
    ];

    $published = (new PublishRegionalPack())->handle($pack, $artifacts);
    $tested = (new RunComplianceTests())->handle($published);

    $passed = $tested->artifacts()->where('key', 'compliance_test-base')->first();
    $failed = $tested->artifacts()->where('key', 'compliance_test-mismatch')->first();
    $nonTest = $tested->artifacts()->where('type', RegionalArtifactType::Tax)->first();

    expect($passed->status)->toBe('passed')
        ->and($passed->test_results['passed'])->toBeTrue()
        ->and($passed->test_results)->toHaveKey('executed_at');

    expect($failed->status)->toBe('failed')
        ->and($failed->test_results['passed'])->toBeFalse();

    expect($nonTest->status)->toBe('active')
        ->and($nonTest->test_results)->toBeNull();
});
