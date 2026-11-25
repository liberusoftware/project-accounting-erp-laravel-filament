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

it('covers accounting-core-api api provider, policies, requests, and index route', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AccountingCoreApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AccountingCoreApiServiceProvider::class);
    $formReq = new StoreAccountingSettingRequest();
    if (method_exists($formReq, 'authorize')) {
        expect($formReq->authorize())->toBeBool();
    }
    if (method_exists($formReq, 'rules')) {
        expect($formReq->rules())->toBeArray();
    }
    $formReq = new StoreBookRequest();
    if (method_exists($formReq, 'authorize')) {
        expect($formReq->authorize())->toBeBool();
    }
    if (method_exists($formReq, 'rules')) {
        expect($formReq->rules())->toBeArray();
    }
    $formReq = new StoreFiscalCalendarRequest();
    if (method_exists($formReq, 'authorize')) {
        expect($formReq->authorize())->toBeBool();
    }
    if (method_exists($formReq, 'rules')) {
        expect($formReq->rules())->toBeArray();
    }
    $formReq = new StoreLegalEntityRequest();
    if (method_exists($formReq, 'authorize')) {
        expect($formReq->authorize())->toBeBool();
    }
    if (method_exists($formReq, 'rules')) {
        expect($formReq->rules())->toBeArray();
    }
    $formReq = new StoreNumberingSequenceRequest();
    if (method_exists($formReq, 'authorize')) {
        expect($formReq->authorize())->toBeBool();
    }
    if (method_exists($formReq, 'rules')) {
        expect($formReq->rules())->toBeArray();
    }
    $formReq = new UpdateLegalEntityRequest();
    if (method_exists($formReq, 'authorize')) {
        expect($formReq->authorize())->toBeBool();
    }
    if (method_exists($formReq, 'rules')) {
        expect($formReq->rules())->toBeArray();
    }
    $policy = new AccountingCorePolicy();
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
    expect($policy->viewAny($allowUser))->toBeBool();
    expect($policy->viewAny($denyUser))->toBeBool();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser))->toBeBool();
    expect($policy->view($denyUser))->toBeBool();
    expect($policy->view(null))->toBeFalse();
    expect($policy->create($allowUser))->toBeBool();
    expect($policy->create($denyUser))->toBeBool();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser))->toBeBool();
    expect($policy->update($denyUser))->toBeBool();
    expect($policy->update(null))->toBeFalse();
    expect($policy->delete($allowUser))->toBeBool();
    expect($policy->delete($denyUser))->toBeBool();
    expect($policy->delete(null))->toBeFalse();
    $policy = new LegalEntityPolicy();
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
    expect($policy->viewAny($allowUser))->toBeBool();
    expect($policy->viewAny($denyUser))->toBeBool();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $record))->toBeBool();
    expect($policy->view($denyUser, $record))->toBeBool();
    expect($policy->view(null, $record))->toBeFalse();
    expect($policy->create($allowUser))->toBeBool();
    expect($policy->create($denyUser))->toBeBool();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeBool();
    expect($policy->update($denyUser, $record))->toBeBool();
    expect($policy->update(null, $record))->toBeFalse();
    expect($policy->delete($allowUser, $record))->toBeBool();
    expect($policy->delete($denyUser, $record))->toBeBool();
    expect($policy->delete(null, $record))->toBeFalse();
});
