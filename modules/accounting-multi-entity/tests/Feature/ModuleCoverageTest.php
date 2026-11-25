<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\MultiEntity\Actions\CreateEntityBook;
use Liberu\Accounting\MultiEntity\Actions\CreateEntityMapping;
use Liberu\Accounting\MultiEntity\Actions\CreateEntityPeriod;
use Liberu\Accounting\MultiEntity\Actions\GrantEntityAccess;
use Liberu\Accounting\MultiEntity\Actions\SetMasterDataPolicy;
use Liberu\Accounting\MultiEntity\Actions\SwitchEntity;
use Liberu\Accounting\MultiEntity\Enums\EntityBookStatus;
use Liberu\Accounting\MultiEntity\Enums\EntityPeriodStatus;
use Liberu\Accounting\MultiEntity\Events\EntityBookActivated;
use Liberu\Accounting\MultiEntity\Events\EntitySwitched;
use Liberu\Accounting\MultiEntity\Exceptions\InvalidEntity;
use Liberu\Accounting\MultiEntity\Models\EntityAccess;
use Liberu\Accounting\MultiEntity\Models\EntityBook;
use Liberu\Accounting\MultiEntity\Models\EntityMapping;
use Liberu\Accounting\MultiEntity\Models\EntityPeriod;
use Liberu\Accounting\MultiEntity\Models\EntitySwitch;
use Liberu\Accounting\MultiEntity\Models\MasterDataPolicy;
use Liberu\Accounting\MultiEntity\MultiEntityServiceProvider;
use Liberu\Accounting\MultiEntity\Queries\EntityQuery;

it('registers the service provider and migrations', function (): void {
    expect(app()->getProviders(MultiEntityServiceProvider::class))->not->toBeEmpty()
        ->and(Schema::hasTable('accounting_multi_entity_books'))->toBeTrue()
        ->and(Schema::hasTable('accounting_multi_entity_access'))->toBeTrue()
        ->and(Schema::hasTable('accounting_multi_entity_policies'))->toBeTrue()
        ->and(Schema::hasTable('accounting_multi_entity_periods'))->toBeTrue()
        ->and(Schema::hasTable('accounting_multi_entity_mappings'))->toBeTrue()
        ->and(Schema::hasTable('accounting_multi_entity_switches'))->toBeTrue();
});

it('exposes multi-entity enums and exception', function (): void {
    expect(array_map(fn (EntityBookStatus $s) => $s->value, EntityBookStatus::cases()))
        ->toBe(['draft', 'active', 'suspended', 'archived'])
        ->and(array_map(fn (EntityPeriodStatus $s) => $s->value, EntityPeriodStatus::cases()))
        ->toBe(['open', 'closed', 'locked']);

    $exception = new InvalidEntity('Bad entity');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Bad entity');
});

it('creates entity books, grants default access, sets policies, creates periods & mappings, and switches entities', function (): void {
    $createBook = new CreateEntityBook();
    $grantAccess = new GrantEntityAccess();
    $setPolicy = new SetMasterDataPolicy();
    $createPeriod = new CreateEntityPeriod();
    $createMapping = new CreateEntityMapping();
    $switchEntity = new SwitchEntity();

    $uk = $createBook->handle([
        'team_id' => 15,
        'entity_ref' => 'ENT-UK',
        'code' => 'UK01',
        'name' => 'Liberu UK Ltd',
        'base_currency' => 'gbp',
        'timezone' => 'Europe/London',
        'tax_registration' => 'GB123456789',
    ]);
    $us = $createBook->handle([
        'team_id' => 15,
        'entity_ref' => 'ENT-US',
        'code' => 'US01',
        'name' => 'Liberu US Inc',
        'base_currency' => 'usd',
    ]);

    expect($uk)->toBeInstanceOf(EntityBook::class)
        ->and($uk->base_currency)->toBe('GBP')
        ->and($uk->status)->toBe(EntityBookStatus::Active)
        ->and($createBook->handle([
            'team_id' => 15,
            'entity_ref' => 'ENT-UK',
            'code' => 'UK01',
            'name' => 'Liberu UK Ltd',
            'base_currency' => 'GBP',
        ])->id)->toBe($uk->id);

    // Grant access with default=true clears previous default for same user
    $accessUk = $grantAccess->handle($uk, 'user-99', 'controller', ['approve', 'post'], default: true);
    expect($accessUk)->toBeInstanceOf(EntityAccess::class)
        ->and($accessUk->is_default)->toBeTrue()
        ->and($accessUk->permissions)->toBe(['approve', 'post'])
        ->and($accessUk->entity->is($uk))->toBeTrue();

    $accessUs = $grantAccess->handle($us, 'user-99', 'viewer', ['read'], default: true);
    expect($accessUs->is_default)->toBeTrue()
        ->and($accessUk->refresh()->is_default)->toBeFalse();

    // Master data policy
    $policy = $setPolicy->handle($uk, 'chart_of_accounts', 'shared', ['lock_parent' => true]);
    expect($policy)->toBeInstanceOf(MasterDataPolicy::class)
        ->and($policy->mode)->toBe('shared')
        ->and($policy->entity->is($uk))->toBeTrue()
        ->and($uk->policies()->count())->toBe(1);

    // Non-overlapping periods
    $p1 = $createPeriod->handle($uk, [
        'period_ref' => '2026-01',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-01-31',
        'tax_configuration' => ['vat_rate' => 20],
    ]);
    $p2 = $createPeriod->handle($uk, [
        'period_ref' => '2026-02',
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-02-28',
    ]);
    expect($p1)->toBeInstanceOf(EntityPeriod::class)
        ->and($p1->status)->toBe(EntityPeriodStatus::Open)
        ->and($p1->entity->is($uk))->toBeTrue()
        ->and($uk->periods()->count())->toBe(2)
        ->and($p2->period_ref)->toBe('2026-02');

    // Entity mapping
    $mapping = $createMapping->handle($uk, 'intercompany', 'IC-US', '2100');
    expect($mapping)->toBeInstanceOf(EntityMapping::class)
        ->and($mapping->is_active)->toBeTrue()
        ->and($mapping->entity->is($uk))->toBeTrue()
        ->and($uk->mappings()->count())->toBe(1);

    // Switch entity
    $switch = $switchEntity->handle($uk, 'user-99', 'sess-abc');
    expect($switch)->toBeInstanceOf(EntitySwitch::class)
        ->and($switch->entity->is($uk))->toBeTrue()
        ->and($switch->switched_at)->not->toBeNull();

    // Events
    Event::fake([EntityBookActivated::class, EntitySwitched::class]);
    event(new EntityBookActivated($uk));
    event(new EntitySwitched($switch));
    Event::assertDispatched(EntityBookActivated::class, fn (EntityBookActivated $e) => $e->entity->is($uk));
    Event::assertDispatched(EntitySwitched::class, fn (EntitySwitched $e) => $e->switch->is($switch));
});

it('throws InvalidEntity on all validation, overlap, policy, and access errors', function (): void {
    $createBook = new CreateEntityBook();
    $grantAccess = new GrantEntityAccess();
    $setPolicy = new SetMasterDataPolicy();
    $createPeriod = new CreateEntityPeriod();
    $createMapping = new CreateEntityMapping();
    $switchEntity = new SwitchEntity();

    expect(fn () => $createBook->handle(['entity_ref' => '', 'code' => 'C', 'name' => 'N', 'base_currency' => 'USD']))
        ->toThrow(InvalidEntity::class, 'Entity field [entity_ref] is required.');

    expect(fn () => $createBook->handle(['entity_ref' => 'E1', 'code' => 'C', 'name' => 'N', 'base_currency' => 'US']))
        ->toThrow(InvalidEntity::class, 'Base currency must be an ISO 4217 code.');

    $book = $createBook->handle([
        'team_id' => 22,
        'entity_ref' => 'E-ERR',
        'code' => 'ERR',
        'name' => 'Error Entity',
        'base_currency' => 'EUR',
    ]);

    expect(fn () => $grantAccess->handle($book, '', 'admin'))
        ->toThrow(InvalidEntity::class, 'User reference and role are required.');

    expect(fn () => $setPolicy->handle($book, 'vendors', 'invalid_mode'))
        ->toThrow(InvalidEntity::class, 'Policy key and supported mode are required.');

    expect(fn () => $createMapping->handle($book, '', 's', 't'))
        ->toThrow(InvalidEntity::class, 'Mapping type, source, and target are required.');

    expect(fn () => $createPeriod->handle($book, [
        'period_ref' => 'P-BAD',
        'starts_on' => '2026-05-10',
        'ends_on' => '2026-05-01',
    ]))->toThrow(InvalidEntity::class, 'Period reference and an ordered date range are required.');

    $createPeriod->handle($book, [
        'period_ref' => 'P-1',
        'starts_on' => '2026-05-01',
        'ends_on' => '2026-05-31',
    ]);

    expect(fn () => $createPeriod->handle($book, [
        'period_ref' => 'P-OVERLAP',
        'starts_on' => '2026-05-15',
        'ends_on' => '2026-06-15',
    ]))->toThrow(InvalidEntity::class, 'The period overlaps an existing entity period.');

    expect(fn () => $switchEntity->handle($book, '', 'sess'))
        ->toThrow(InvalidEntity::class, 'User and session references are required.');

    expect(fn () => $switchEntity->handle($book, 'unauthorized-user', 'sess'))
        ->toThrow(InvalidEntity::class, 'The user has no access to this entity.');
});

it('queries entity books with filters, clamping, and summary report', function (): void {
    $query = new EntityQuery();
    expect($query->paginate()->total())->toBe(0);

    $book = (new CreateEntityBook())->handle([
        'team_id' => 30,
        'entity_ref' => 'ENT-Q',
        'code' => 'Q01',
        'name' => 'Query Entity',
        'base_currency' => 'USD',
    ]);
    (new GrantEntityAccess())->handle($book, 'u1', 'admin');
    (new SetMasterDataPolicy())->handle($book, 'tax', 'local');
    (new CreateEntityPeriod())->handle($book, ['period_ref' => 'P1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31']);
    (new CreateEntityMapping())->handle($book, 'coa', '100', '1000');

    expect($query->paginate()->total())->toBe(1)
        ->and($query->paginate(teamId: 30, status: 'active')->total())->toBe(1)
        ->and($query->paginate(status: 'archived')->total())->toBe(0)
        ->and($query->paginate(perPage: 0)->perPage())->toBe(1)
        ->and($query->paginate(perPage: 500)->perPage())->toBe(100)
        ->and($query->report($book))->toBe([
            'entity_ref' => 'ENT-Q',
            'code' => 'Q01',
            'name' => 'Query Entity',
            'base_currency' => 'USD',
            'access_count' => 1,
            'policy_count' => 1,
            'open_periods' => 1,
            'mapping_count' => 1,
        ]);
});
