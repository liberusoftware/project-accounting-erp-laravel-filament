<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PurchaseRequisitions\Actions\RecordApproval;
use Liberu\Accounting\PurchaseRequisitions\Actions\TransitionRequisition;
use Liberu\Accounting\PurchaseRequisitions\Enums\RequisitionStatus;
use Liberu\Accounting\PurchaseRequisitions\Models\PurchaseRequisition;
use Liberu\Accounting\PurchaseRequisitionsLivewire\Livewire\PurchaseRequisitions;
use Liberu\Accounting\PurchaseRequisitionsLivewire\PurchaseRequisitionsLivewireServiceProvider;

it('covers accounting-purchase-requisitions-livewire components', function (): void {
    $provider = $this->app->register(PurchaseRequisitionsLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create(['current_team_id' => 1]));

    $req = PurchaseRequisition::query()->create([
        'team_id' => 1,
        'requester_ref' => 'USR-1',
        'title' => 'Laptop',
        'currency' => 'GBP',
        'total_amount' => 100.00,
        'lines' => [],
        'status' => RequisitionStatus::Draft,
    ]);

    $component = new PurchaseRequisitions();
    $component->selectRequisition((int) $req->getKey());
    $component->status = 'submitted';
    $component->transitionStatus(app(TransitionRequisition::class));
    expect($req->refresh()->status)->toBe(RequisitionStatus::Submitted);

    $component->selectRequisition((int) $req->getKey());
    $component->approverRef = 'MGR-1';
    $component->decision = 'approved';
    $component->reason = 'Looks good';
    $component->approve(app(RecordApproval::class));
    expect($component->render())->not->toBeNull();
});
