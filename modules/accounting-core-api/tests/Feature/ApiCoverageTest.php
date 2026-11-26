<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\CoreApi\AccountingCoreApiServiceProvider;
use Liberu\Accounting\CoreApi\Http\Requests\StoreAccountingSettingRequest;
use Liberu\Accounting\CoreApi\Http\Requests\StoreBookRequest;
use Liberu\Accounting\CoreApi\Http\Requests\StoreFiscalCalendarRequest;
use Liberu\Accounting\CoreApi\Http\Requests\StoreLegalEntityRequest;
use Liberu\Accounting\CoreApi\Http\Requests\StoreNumberingSequenceRequest;
use Liberu\Accounting\CoreApi\Http\Requests\UpdateLegalEntityRequest;
use Liberu\Accounting\CoreApi\Policies\AccountingCorePolicy;
use Liberu\Accounting\CoreApi\Policies\LegalEntityPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-core-api api provider, policies, requests, resources, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AccountingCoreApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AccountingCoreApiServiceProvider::class);

    foreach ([
        new StoreAccountingSettingRequest(),
        new StoreBookRequest(),
        new StoreFiscalCalendarRequest(),
        new StoreLegalEntityRequest(),
        new StoreNumberingSequenceRequest(),
        new UpdateLegalEntityRequest(),
    ] as $formReq) {
        expect($formReq->authorize())->toBeBool();
        expect($formReq->rules())->toBeArray();
    }

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
    $record = (new LegalEntity())->forceFill(['team_id' => 1, 'legal_entity_id' => 1]);

    $corePolicy = new AccountingCorePolicy();
    expect($corePolicy->viewAny($allowUser))->toBeTrue();
    expect($corePolicy->viewAny($denyUser))->toBeFalse();
    expect($corePolicy->viewAny(null))->toBeFalse();
    expect($corePolicy->view($allowUser))->toBeTrue();
    expect($corePolicy->view($denyUser))->toBeFalse();
    expect($corePolicy->view(null))->toBeFalse();
    expect($corePolicy->create($allowUser))->toBeTrue();
    expect($corePolicy->create($denyUser))->toBeFalse();
    expect($corePolicy->create(null))->toBeFalse();
    expect($corePolicy->update($allowUser))->toBeTrue();
    expect($corePolicy->update($denyUser))->toBeFalse();
    expect($corePolicy->update(null))->toBeFalse();
    expect($corePolicy->delete($allowUser))->toBeTrue();
    expect($corePolicy->delete($denyUser))->toBeFalse();
    expect($corePolicy->delete(null))->toBeFalse();

    $entityPolicy = new LegalEntityPolicy();
    expect($entityPolicy->viewAny($allowUser))->toBeTrue();
    expect($entityPolicy->viewAny($denyUser))->toBeFalse();
    expect($entityPolicy->viewAny(null))->toBeFalse();
    expect($entityPolicy->view($allowUser, $record))->toBeTrue();
    expect($entityPolicy->view($denyUser, $record))->toBeFalse();
    expect($entityPolicy->view(null, $record))->toBeFalse();
    expect($entityPolicy->create($allowUser))->toBeTrue();
    expect($entityPolicy->create($denyUser))->toBeFalse();
    expect($entityPolicy->create(null))->toBeFalse();
    expect($entityPolicy->update($allowUser, $record))->toBeTrue();
    expect($entityPolicy->update($denyUser, $record))->toBeFalse();
    expect($entityPolicy->update(null, $record))->toBeFalse();
    expect($entityPolicy->delete($allowUser, $record))->toBeTrue();
    expect($entityPolicy->delete($denyUser, $record))->toBeFalse();
    expect($entityPolicy->delete(null, $record))->toBeFalse();

    $entityStore = $this->postJson('/api/v1/accounting/accounting-core/legal-entities', [
        'name' => 'Liberu Holdings Ltd',
        'registration_number' => 'GB-123456',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
    ]);
    $entityStore->assertStatus(201);
    $entityId = (string) $entityStore->json('data.id');

    $this->getJson('/api/v1/accounting/accounting-core/legal-entities')->assertOk();
    $this->getJson("/api/v1/accounting/accounting-core/legal-entities/{$entityId}")->assertOk();
    $this->patchJson("/api/v1/accounting/accounting-core/legal-entities/{$entityId}", [
        'name' => 'Liberu Holdings Updated Ltd',
        'is_active' => true,
    ])->assertOk();

    $disposableEntity = $this->postJson('/api/v1/accounting/accounting-core/legal-entities', [
        'name' => 'Disposable Entity Ltd',
        'currency_code' => 'USD',
        'accounting_basis' => 'cash',
    ]);
    $disposableEntity->assertStatus(201);
    $disposableEntityId = (string) $disposableEntity->json('data.id');
    $this->deleteJson("/api/v1/accounting/accounting-core/legal-entities/{$disposableEntityId}")->assertNoContent();

    $bookStore = $this->postJson('/api/v1/accounting/accounting-core/books', [
        'legal_entity_id' => (int) $entityId,
        'name' => 'Primary Ledger',
        'code' => 'MAIN-01',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
    expect($bookStore->getStatusCode())->toBeIn([200, 201]);
    $bookId = (string) $bookStore->json('data.id');

    $this->getJson('/api/v1/accounting/accounting-core/books')->assertOk();
    $this->getJson("/api/v1/accounting/accounting-core/books/{$bookId}")->assertOk();

    $calendarStore = $this->postJson("/api/v1/accounting/accounting-core/books/{$bookId}/fiscal-calendars", [
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'is_closed' => false,
    ]);
    expect($calendarStore->getStatusCode())->toBeIn([200, 201]);

    // Trigger overlapping InvalidFiscalCalendar catch branch
    $this->postJson("/api/v1/accounting/accounting-core/books/{$bookId}/fiscal-calendars", [
        'starts_on' => '2026-06-01',
        'ends_on' => '2027-05-31',
    ])->assertStatus(422);

    $this->getJson("/api/v1/accounting/accounting-core/books/{$bookId}/fiscal-calendars")->assertOk();

    $seqStore = $this->postJson("/api/v1/accounting/accounting-core/books/{$bookId}/numbering-sequences", [
        'key' => 'invoice',
        'prefix' => 'INV-',
        'next_number' => 1,
        'padding' => 5,
    ]);
    expect($seqStore->getStatusCode())->toBeIn([200, 201]);
    $seqId = (string) $seqStore->json('data.id');

    $this->getJson("/api/v1/accounting/accounting-core/books/{$bookId}/numbering-sequences")->assertOk();
    $this->postJson("/api/v1/accounting/accounting-core/books/{$bookId}/numbering-sequences/{$seqId}/allocate")->assertOk();

    foreach (['defaults', 'policies'] as $setting) {
        $settingStore = $this->postJson("/api/v1/accounting/accounting-core/books/{$bookId}/{$setting}", [
            'key' => "sample_{$setting}",
            'value' => ['enabled' => true],
        ]);
        expect($settingStore->getStatusCode())->toBeIn([200, 201]);
        $settingId = (string) $settingStore->json('data.id');

        $this->getJson("/api/v1/accounting/accounting-core/books/{$bookId}/{$setting}")->assertOk();
        $this->getJson("/api/v1/accounting/accounting-core/books/{$bookId}/{$setting}/{$settingId}")->assertOk();
        $this->patchJson("/api/v1/accounting/accounting-core/books/{$bookId}/{$setting}/{$settingId}", [
            'value' => ['enabled' => false],
        ])->assertOk();
        $this->deleteJson("/api/v1/accounting/accounting-core/books/{$bookId}/{$setting}/{$settingId}")->assertNoContent();
    }
});
