<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\PurchaseRequisitions\Actions\CreateRequisition;
use Liberu\Accounting\PurchaseRequisitions\Actions\RecordApproval;
use Liberu\Accounting\PurchaseRequisitions\Actions\TransitionRequisition;
use Liberu\Accounting\PurchaseRequisitions\Enums\RequisitionStatus;
use Liberu\Accounting\PurchaseRequisitions\Exceptions\InvalidRequisition;
use Liberu\Accounting\PurchaseRequisitions\Models\PurchaseRequisition;
use Liberu\Accounting\PurchaseRequisitions\Models\RequisitionApproval;
use Liberu\Accounting\PurchaseRequisitions\PurchaseRequisitionsServiceProvider;

it('boots the service provider and loads purchase requisition tables', function (): void {
    expect(app()->getProvider(PurchaseRequisitionsServiceProvider::class))
        ->toBeInstanceOf(PurchaseRequisitionsServiceProvider::class);

    expect(Schema::hasTable('accounting_purchase_requisitions'))->toBeTrue()
        ->and(Schema::hasTable('accounting_requisition_approvals'))->toBeTrue();
});

it('exposes all requisition status enum cases', function (): void {
    expect(array_map(fn (RequisitionStatus $c): string => $c->value, RequisitionStatus::cases()))->toBe([
        'draft',
        'submitted',
        'approved',
        'sourcing',
        'converted',
        'rejected',
        'cancelled',
    ]);
});

it('creates requisitions and validates required attributes', function (): void {
    $create = app(CreateRequisition::class);

    $req = $create->handle([
        'team_id' => 6,
        'requester_ref' => 'user-42',
        'title' => 'Q2 Office Equipment',
        'currency' => 'gbp',
        'total_amount' => 1234.567,
        'lines' => [['item' => 'Desk', 'qty' => 2, 'amount' => 1234.57]],
        'coding' => ['department' => 'OPS'],
        'budget' => ['limit' => 2000],
        'attachments' => [['name' => 'quote.pdf']],
        'metadata' => ['urgent' => true],
    ]);

    expect($req)->toBeInstanceOf(PurchaseRequisition::class)
        ->and($req->currency)->toBe('GBP')
        ->and((float) $req->total_amount)->toBe(1234.57)
        ->and($req->status)->toBe(RequisitionStatus::Draft)
        ->and($req->coding)->toBe(['department' => 'OPS'])
        ->and($req->budget)->toBe(['limit' => 2000])
        ->and($req->attachments)->toBe([['name' => 'quote.pdf']])
        ->and($req->metadata)->toBe(['urgent' => true]);

    expect(fn () => $create->handle([
        'requester_ref' => '',
        'currency' => 'GBP',
        'total_amount' => 100,
        'lines' => [['item' => 'A']],
    ]))->toThrow(InvalidRequisition::class, 'Requester, currency, positive total, and at least one line are required.');

    expect(fn () => $create->handle([
        'requester_ref' => 'user-1',
        'currency' => '',
        'total_amount' => 100,
        'lines' => [['item' => 'A']],
    ]))->toThrow(InvalidRequisition::class);

    expect(fn () => $create->handle([
        'requester_ref' => 'user-1',
        'currency' => 'GBP',
        'total_amount' => 0,
        'lines' => [['item' => 'A']],
    ]))->toThrow(InvalidRequisition::class);

    expect(fn () => $create->handle([
        'requester_ref' => 'user-1',
        'currency' => 'GBP',
        'total_amount' => 100,
        'lines' => [],
    ]))->toThrow(InvalidRequisition::class);

    expect(fn () => $create->handle([
        'requester_ref' => 'user-1',
        'currency' => 'GBP',
        'total_amount' => 100,
        'lines' => 'invalid',
    ]))->toThrow(InvalidRequisition::class);
});

it('transitions requisitions through sourcing and conversion and enforces invalid transitions', function (): void {
    $create = app(CreateRequisition::class);
    $transition = app(TransitionRequisition::class);

    $req = $create->handle([
        'requester_ref' => 'user-1',
        'currency' => 'USD',
        'total_amount' => 500,
        'lines' => [['item' => 'Monitor']],
    ]);

    // Invalid Draft -> Approved
    expect(fn () => $transition->handle($req, RequisitionStatus::Approved))
        ->toThrow(InvalidRequisition::class, 'Cannot transition from draft to approved.');

    // Draft -> Submitted -> Approved -> Sourcing -> Converted
    $req = $transition->handle($req, RequisitionStatus::Submitted);
    expect($req->status)->toBe(RequisitionStatus::Submitted)
        ->and($req->submitted_at)->not->toBeNull();

    $req = $transition->handle($req, RequisitionStatus::Approved);
    expect($req->status)->toBe(RequisitionStatus::Approved)
        ->and($req->approved_at)->not->toBeNull();

    $req = $transition->handle($req, RequisitionStatus::Sourcing, ['sourcing_ref' => 'RFQ-99']);
    expect($req->status)->toBe(RequisitionStatus::Sourcing)
        ->and($req->sourcing_ref)->toBe('RFQ-99');

    $req = $transition->handle($req, RequisitionStatus::Converted, ['converted_ref' => 'PO-99']);
    expect($req->status)->toBe(RequisitionStatus::Converted)
        ->and($req->converted_ref)->toBe('PO-99');

    // Terminal Converted rejects transitions
    expect(fn () => $transition->handle($req, RequisitionStatus::Cancelled))
        ->toThrow(InvalidRequisition::class, 'Cannot transition from converted to cancelled.');

    // Draft -> Cancelled and Approved -> Converted directly and Sourcing -> Cancelled
    $cancelledDraft = $transition->handle($create->handle([
        'requester_ref' => 'u2',
        'currency' => 'GBP',
        'total_amount' => 10,
        'lines' => [['item' => 'Pen']],
    ]), RequisitionStatus::Cancelled);
    expect($cancelledDraft->status)->toBe(RequisitionStatus::Cancelled);

    $directConvert = $create->handle([
        'requester_ref' => 'u3',
        'currency' => 'GBP',
        'total_amount' => 20,
        'lines' => [['item' => 'Paper']],
    ]);
    $directConvert = $transition->handle($directConvert, RequisitionStatus::Submitted);
    $directConvert = $transition->handle($directConvert, RequisitionStatus::Approved);
    $directConvert = $transition->handle($directConvert, RequisitionStatus::Converted, ['converted_ref' => 'PO-DIRECT']);
    expect($directConvert->converted_ref)->toBe('PO-DIRECT');

    $sourcingCancel = $create->handle([
        'requester_ref' => 'u4',
        'currency' => 'GBP',
        'total_amount' => 30,
        'lines' => [['item' => 'Ink']],
    ]);
    $sourcingCancel = $transition->handle($sourcingCancel, RequisitionStatus::Submitted);
    $sourcingCancel = $transition->handle($sourcingCancel, RequisitionStatus::Approved);
    $sourcingCancel = $transition->handle($sourcingCancel, RequisitionStatus::Sourcing);
    expect($transition->handle($sourcingCancel, RequisitionStatus::Cancelled)->status)->toBe(RequisitionStatus::Cancelled);
});

it('records requisition approvals and rejections and validates decision rules', function (): void {
    $create = app(CreateRequisition::class);
    $transition = app(TransitionRequisition::class);
    $recordApproval = app(RecordApproval::class);

    $draft = $create->handle([
        'requester_ref' => 'u10',
        'currency' => 'GBP',
        'total_amount' => 250,
        'lines' => [['item' => 'Chair']],
    ]);

    // Cannot approve a draft requisition
    expect(fn () => $recordApproval->handle($draft, [
        'approver_ref' => 'mgr-1',
        'decision' => 'approved',
    ]))->toThrow(InvalidRequisition::class, 'Only submitted requisitions can be approved.');

    $submitted = $transition->handle($draft, RequisitionStatus::Submitted);

    // Missing approver_ref
    expect(fn () => $recordApproval->handle($submitted, [
        'approver_ref' => '',
        'decision' => 'approved',
    ]))->toThrow(InvalidRequisition::class, 'Only submitted requisitions can be approved.');

    // Invalid decision
    expect(fn () => $recordApproval->handle($submitted, [
        'approver_ref' => 'mgr-1',
        'decision' => 'deferred',
    ]))->toThrow(InvalidRequisition::class, 'Approval decision is invalid.');

    // Approved
    $approval = $recordApproval->handle($submitted, [
        'approver_ref' => 'mgr-1',
        'decision' => 'approved',
        'reason' => 'Within budget',
    ]);

    $submitted->refresh();
    expect($approval)->toBeInstanceOf(RequisitionApproval::class)
        ->and($approval->decision)->toBe('approved')
        ->and($approval->decided_at)->not->toBeNull()
        ->and($submitted->status)->toBe(RequisitionStatus::Approved)
        ->and($submitted->approved_at)->not->toBeNull()
        ->and($submitted->approvals)->toHaveCount(1);

    // Rejected
    $toReject = $transition->handle($create->handle([
        'requester_ref' => 'u11',
        'currency' => 'GBP',
        'total_amount' => 9000,
        'lines' => [['item' => 'Server']],
    ]), RequisitionStatus::Submitted);

    $rejection = $recordApproval->handle($toReject, [
        'approver_ref' => 'cfo-1',
        'decision' => 'rejected',
        'reason' => 'Over budget',
    ]);

    $toReject->refresh();
    expect($rejection->decision)->toBe('rejected')
        ->and($toReject->status)->toBe(RequisitionStatus::Rejected)
        ->and($toReject->approved_at)->toBeNull();
});
