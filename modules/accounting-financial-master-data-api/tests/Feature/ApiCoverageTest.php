<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FinancialMasterData\Models\Party;
use Liberu\Accounting\FinancialMasterDataApi\FinancialMasterDataApiServiceProvider;
use Liberu\Accounting\FinancialMasterDataApi\Http\Controllers\PartyDetailController;
use Liberu\Accounting\FinancialMasterDataApi\Http\Controllers\ReferenceDataController;
use Liberu\Accounting\FinancialMasterDataApi\Http\Requests\StorePartyDetailRequest;
use Liberu\Accounting\FinancialMasterDataApi\Http\Requests\StorePartyRequest;
use Liberu\Accounting\FinancialMasterDataApi\Http\Requests\StoreReferenceDataRequest;
use Liberu\Accounting\FinancialMasterDataApi\Http\Requests\UpdatePartyRequest;
use Liberu\Accounting\FinancialMasterDataApi\Http\Requests\UpdateReferenceDataRequest;
use Liberu\Accounting\FinancialMasterDataApi\Policies\PartyPolicy;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('covers accounting-financial-master-data-api provider, policies, requests, controllers, and resources', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(FinancialMasterDataApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(FinancialMasterDataApiServiceProvider::class);

    foreach ([
        StorePartyDetailRequest::class,
        StorePartyRequest::class,
        StoreReferenceDataRequest::class,
        UpdatePartyRequest::class,
        UpdateReferenceDataRequest::class,
    ] as $requestClass) {
        $formReq = new $requestClass();
        expect($formReq->authorize())->toBeBool();
        expect($formReq->rules())->toBeArray();
    }

    $policy = new PartyPolicy();
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
    $record = (new Party())->forceFill(['team_id' => 1, 'legal_entity_id' => 1]);
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

    $legalEntityId = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'Master Data Corp',
        'registration_number' => 'MD-001',
        'currency_code' => 'USD',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $base = '/api/v1/accounting/financial-master-data';

    // PartyController
    $party1Res = $this->postJson("{$base}/parties", [
        'legal_entity_id' => $legalEntityId,
        'type' => 'customer',
        'reference' => 'CUST-001',
        'name' => 'Acme Customer',
        'email' => 'acme@example.test',
    ]);
    expect($party1Res->status())->toBe(201);
    $party1Id = $party1Res->json('data.id');

    // Duplicate on store -> 422
    expect($this->postJson("{$base}/parties", [
        'legal_entity_id' => $legalEntityId,
        'type' => 'customer',
        'name' => 'Acme Customer',
    ])->status())->toBe(422);

    $party2Res = $this->postJson("{$base}/parties", [
        'legal_entity_id' => $legalEntityId,
        'type' => 'customer',
        'reference' => 'CUST-002',
        'name' => 'Beta Customer',
    ]);
    expect($party2Res->status())->toBe(201);
    $party2Id = $party2Res->json('data.id');

    expect($this->getJson("{$base}/parties?type=customer&legal_entity_id={$legalEntityId}")->status())->toBe(200);
    expect($this->getJson("{$base}/parties/{$party1Id}")->status())->toBe(200);
    expect($this->patchJson("{$base}/parties/{$party1Id}", ['phone' => '555-1234'])->status())->toBe(200);
    // Duplicate on update -> 422
    expect($this->patchJson("{$base}/parties/{$party2Id}", ['name' => 'Acme Customer'])->status())->toBe(422);
    expect($this->deleteJson("{$base}/parties/{$party2Id}")->status())->toBe(204);

    // PartyDetailController: addresses & bank-details
    $addrRes = $this->postJson("{$base}/parties/{$party1Id}/addresses", [
        'kind' => 'billing',
        'line_one' => '100 High St',
        'city' => 'London',
        'country_code' => 'GB',
        'is_primary' => true,
    ]);
    expect($addrRes->status())->toBe(201);
    $addrId = $addrRes->json('data.id');
    expect($this->getJson("{$base}/parties/{$party1Id}/addresses")->status())->toBe(200);
    request()->setUserResolver(fn () => $user);
    expect((new PartyDetailController())->destroy((string) $party1Id, 'addresses', (string) $addrId)->getStatusCode())->toBe(204);

    $bankRes = $this->postJson("{$base}/parties/{$party1Id}/bank-details", [
        'label' => 'Primary Bank',
        'bank_name' => 'Barclays',
        'country_code' => 'GB',
        'currency_code' => 'GBP',
        'credential_reference' => 'vault://bank/1',
        'is_primary' => true,
    ]);
    expect($bankRes->status())->toBe(201);
    $bankId = $bankRes->json('data.id');
    expect($this->getJson("{$base}/parties/{$party1Id}/bank-details")->status())->toBe(200);
    request()->setUserResolver(fn () => $user);
    expect((new PartyDetailController())->destroy((string) $party1Id, 'bank-details', (string) $bankId)->getStatusCode())->toBe(204);

    try {
        (new PartyDetailController())->index((string) $party1Id, 'unknown');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }

    // ReferenceDataController: items-services, tax-profiles, payment-terms
    $refPayloads = [
        'items-services' => [
            'store' => ['legal_entity_id' => $legalEntityId, 'sku' => 'SKU-1', 'name' => 'Widget', 'kind' => 'item', 'sales_price' => 50],
            'update' => ['name' => 'Widget Pro'],
        ],
        'tax-profiles' => [
            'store' => ['legal_entity_id' => $legalEntityId, 'code' => 'VAT20', 'name' => 'Standard VAT', 'rate' => 20],
            'update' => ['name' => 'Standard VAT 20%'],
        ],
        'payment-terms' => [
            'store' => ['legal_entity_id' => $legalEntityId, 'code' => 'NET30', 'name' => 'Net 30', 'days' => 30],
            'update' => ['name' => 'Net 30 Days'],
        ],
    ];

    foreach ($refPayloads as $resource => $payloads) {
        $createRes = $this->postJson("{$base}/{$resource}", $payloads['store']);
        expect($createRes->status())->toBe(201);
        $recId = $createRes->json('data.id');

        // Duplicate store -> 422
        expect($this->postJson("{$base}/{$resource}", $payloads['store'])->status())->toBe(422);

        expect($this->getJson("{$base}/{$resource}?legal_entity_id={$legalEntityId}")->status())->toBe(200);
        expect($this->getJson("{$base}/{$resource}/{$recId}")->status())->toBe(200);
        expect($this->patchJson("{$base}/{$resource}/{$recId}", $payloads['update'])->status())->toBe(200);
        expect($this->deleteJson("{$base}/{$resource}/{$recId}")->status())->toBe(204);
    }

    try {
        (new ReferenceDataController())->show('unknown', '1');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
});
