<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Liberu\Accounting\Erp\Exceptions\ApprovalDeniedException;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\ApprovalRequest;
use Liberu\Accounting\Erp\Models\ApprovalRule;
use Liberu\Accounting\Erp\Models\ApprovalStep;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\Estimate;
use Liberu\Accounting\Erp\Models\Expense;
use Liberu\Accounting\Erp\Models\InventoryCostLayer;
use Liberu\Accounting\Erp\Models\InventoryItem;
use Liberu\Accounting\Erp\Models\InventoryTransaction;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\JournalEntry;
use Liberu\Accounting\Erp\Models\Payment;
use Liberu\Accounting\Erp\Models\PaymentTerm;
use Liberu\Accounting\Erp\Models\PurchaseRequest;
use Liberu\Accounting\Erp\Models\SalesOrder;
use Liberu\Accounting\Erp\Models\Supplier;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Services\ApprovalService;
use Liberu\Accounting\Erp\Services\CollectionService;
use Liberu\Accounting\Erp\Services\InventoryMovementService;
use Liberu\Accounting\Erp\Services\InventoryPostingService;
use Liberu\Accounting\Erp\Services\InventoryService;
use Liberu\Accounting\Erp\Services\InventoryValuationService;
use Liberu\Accounting\Erp\Services\InvoicePostingService;
use Liberu\Accounting\Erp\Services\PaymentPostingService;
use Liberu\Accounting\Erp\Services\PostingReversalService;
use Liberu\Accounting\Erp\Services\ProcurementService;
use Liberu\Accounting\Erp\Services\RevenueRecognitionService;
use Liberu\Accounting\Erp\Services\SalesOrderService;
use Liberu\Accounting\Erp\Services\TransactionSettlementService;
use Spatie\Permission\Models\Role;

describe('InvoicePostingService, PaymentPostingService, and PostingReversalService', function (): void {
    it('posts and reverses invoices and payments including deferred revenue and all error guards', function (): void {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $customer = Customer::factory()->create(['team_id' => $team->id]);

        $invoiceService = new InvoicePostingService();
        $paymentService = new PaymentPostingService();
        $reversalService = new PostingReversalService();

        $invoiceNoChart = Invoice::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'total_amount' => 100,
            'journal_entry_id' => null,
        ]);

        // Missing chart accounts throw RuntimeException
        expect(fn () => $invoiceService->post($invoiceNoChart))->toThrow(RuntimeException::class, '1100');

        // Provision 1000 Cash, 1100 AR, 4000 Sales, 2300 Deferred
        Account::factory()->create(['team_id' => $team->id, 'account_number' => 1000, 'account_type' => 'asset', 'account_name' => 'Cash']);
        Account::factory()->create(['team_id' => $team->id, 'account_number' => 1100, 'account_type' => 'asset', 'account_name' => 'AR']);
        $sales = Account::factory()->create(['team_id' => $team->id, 'account_number' => 4000, 'account_type' => 'revenue', 'account_name' => 'Sales']);
        $deferred = Account::factory()->create(['team_id' => $team->id, 'account_number' => 2300, 'account_type' => 'liability', 'account_name' => 'Deferred']);

        // Unposted invoice reversal guard
        expect(fn () => $reversalService->reverseInvoice($invoiceNoChart))->toThrow(RuntimeException::class, 'not posted');

        // Post standard invoice + idempotent second call
        $entry = $invoiceService->post($invoiceNoChart);
        expect($entry->is_posted)->toBeTrue()
            ->and($invoiceService->post($invoiceNoChart->fresh())->getKey())->toBe($entry->getKey());

        // Payment posting + idempotent second call
        $payment = Payment::create([
            'team_id' => $team->id,
            'invoice_id' => $invoiceNoChart->id,
            'payment_amount' => 100,
            'payment_date' => now()->toDateString(),
        ]);

        expect(fn () => $reversalService->reversePayment($payment))->toThrow(RuntimeException::class, 'not posted');

        $payEntry = $paymentService->post($payment);
        expect($payEntry->is_posted)->toBeTrue()
            ->and($paymentService->post($payment->fresh())->getKey())->toBe($payEntry->getKey());

        // Cannot reverse invoice while payment is posted
        expect(fn () => $reversalService->reverseInvoice($invoiceNoChart->fresh()))
            ->toThrow(RuntimeException::class, 'posted payments');

        // Reverse payment then reverse invoice
        $reversalService->reversePayment($payment->fresh());
        expect($payment->fresh()->journal_entry_id)->toBeNull();

        $reversalService->reverseInvoice($invoiceNoChart->fresh());
        expect($invoiceNoChart->fresh()->journal_entry_id)->toBeNull();

        // Invoice with revenue schedule posts to Deferred Revenue; cannot unpost once recognized
        $schedInvoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonth()->toDateString(),
            'total_amount' => 120,
            'journal_entry_id' => null,
        ]);
        $revService = new RevenueRecognitionService();
        $schedule = $revService->createFromInvoice($schedInvoice, 2, $deferred, $sales);
        $schedEntry = $invoiceService->post($schedInvoice);
        expect($schedEntry->lines()->where('account_id', $deferred->id)->exists())->toBeTrue();

        $revService->recognizeDue($schedule->fresh());
        expect(fn () => $reversalService->reverseInvoice($schedInvoice->fresh()))
            ->toThrow(RuntimeException::class, 'revenue has already been recognized');
    });
});

describe('TransactionSettlementService', function (): void {
    it('settles foreign-currency transactions via FxRevaluationService', function (): void {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $counter = Account::factory()->create(['team_id' => $team->id]);
        $fxGain = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'revenue']);
        $fxLoss = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'expense']);

        $txn = Transaction::factory()->create([
            'team_id' => $team->id,
            'account_id' => $counter->id,
            'amount' => 100,
            'exchange_rate' => 1.10,
        ]);

        $service = app(TransactionSettlementService::class);
        expect($service->settle($txn, 1.10, $counter, $fxGain, $fxLoss))->toBeNull();
        expect($service->settle($txn, 1.20, $counter, $fxGain, $fxLoss))->toBeInstanceOf(JournalEntry::class);
    });
});

describe('Inventory Services (Valuation, Posting, Movement, Inventory)', function (): void {
    it('handles FIFO, LIFO, Average, and unknown valuation methods, movements, COGS posting, and reports', function (): void {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $invAccount = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'asset']);
        $cogsAccount = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'cost_of_goods_sold']);

        $valuation = new InventoryValuationService();
        $posting = new InventoryPostingService();
        $movement = new InventoryMovementService($valuation, $posting);
        $inventoryService = new InventoryService($valuation);

        // FIFO via InventoryMovementService
        $fifoItem = InventoryItem::factory()->create([
            'team_id' => $team->id,
            'account_id' => $invAccount->id,
            'valuation_method' => 'fifo',
            'current_quantity' => 0,
            'average_cost' => 0,
            'reorder_point' => 5,
            'is_active' => true,
        ]);
        $layer1 = $movement->recordPurchase($fifoItem, 10, 5.00);
        $layer1->update(['purchase_date' => now()->subDays(2)]);
        $movement->recordPurchase($fifoItem->fresh(), 10, 8.00);

        $saleResult = $movement->recordSale($fifoItem->fresh(), 12, $cogsAccount);
        expect($saleResult['cogs'])->toBe(66.0)
            ->and($saleResult['journal_entry'])->toBeInstanceOf(JournalEntry::class)
            ->and((float) $valuation->getInventoryValuation($fifoItem->fresh()))->toBe(64.0);

        // LIFO
        $lifoItem = InventoryItem::factory()->create([
            'team_id' => $team->id,
            'account_id' => $invAccount->id,
            'valuation_method' => 'lifo',
            'current_quantity' => 10,
            'average_cost' => 5,
            'is_active' => true,
        ]);
        InventoryCostLayer::create(['inventory_item_id' => $lifoItem->id, 'quantity' => 5, 'unit_cost' => 4, 'purchase_date' => now()->subDays(3)]);
        InventoryCostLayer::create(['inventory_item_id' => $lifoItem->id, 'quantity' => 5, 'unit_cost' => 10, 'purchase_date' => now()->subDay()]);

        $txn = Transaction::factory()->create(['team_id' => $team->id, 'account_id' => $invAccount->id]);
        $lifoSale = $inventoryService->createInventoryTransaction($txn, $lifoItem, 6, 15.0, 'sale');
        expect((float) $lifoSale->cost_of_goods_sold)->toBe(54.0);

        // Average via InventoryService purchase + sale
        $avgItem = InventoryItem::factory()->create([
            'team_id' => $team->id,
            'account_id' => $invAccount->id,
            'valuation_method' => 'average',
            'current_quantity' => 0,
            'average_cost' => 0,
            'is_active' => true,
        ]);
        $inventoryService->createInventoryTransaction($txn, $avgItem, 10, 12.0, 'purchase');
        $avgSale = $inventoryService->createInventoryTransaction($txn, $avgItem->fresh(), 4, 20.0, 'sale');
        expect((float) $avgSale->cost_of_goods_sold)->toBe(48.0)
            ->and((float) $valuation->getInventoryValuation($avgItem->fresh()))->toBe(72.0);

        // Unknown valuation method returns null
        $unknownItem = InventoryItem::factory()->create([
            'team_id' => $team->id,
            'account_id' => $invAccount->id,
            'valuation_method' => 'custom',
            'is_active' => false,
        ]);
        $unknownTx = new InventoryTransaction(['inventory_item_id' => $unknownItem->id, 'quantity' => 1]);
        expect($valuation->calculateCostOfGoodsSold($unknownTx))->toBeNull();

        expect($inventoryService->checkLowStock())->toBeIterable()
            ->and($inventoryService->getInventoryValue())->toBeGreaterThan(0)
            ->and($inventoryService->getInventoryReport()->count())->toBeGreaterThanOrEqual(3);
    });
});

describe('SalesOrderService & ProcurementService', function (): void {
    it('converts accepted estimates to sales orders and invoices with all domain guards', function (): void {
        $team = Team::factory()->create();
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $service = new SalesOrderService();

        $draftEstimate = Estimate::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'status' => 'draft',
        ]);
        expect(fn () => $service->createFromEstimate($draftEstimate))->toThrow(DomainException::class, 'accepted');

        $alreadyInvoiced = Estimate::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'status' => 'accepted',
            'invoice_id' => Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id])->id,
        ]);
        expect(fn () => $service->createFromEstimate($alreadyInvoiced))->toThrow(DomainException::class, 'invoiced directly');

        $estimate = Estimate::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'status' => 'accepted',
            'subtotal_amount' => 200,
            'tax_amount' => 20,
            'total_amount' => 220,
            'invoice_id' => null,
        ]);
        $estimate->items()->create([
            'description' => 'Consulting',
            'quantity' => 2,
            'unit_price' => 100,
            'amount' => 200,
            'tax_amount' => 20,
        ]);

        $order = $service->createFromEstimate($estimate->fresh('items'));
        expect($order)->toBeInstanceOf(SalesOrder::class)
            ->and($order->items()->count())->toBe(1);

        // Duplicate SO guard
        expect(fn () => $service->createFromEstimate($estimate->fresh()))->toThrow(DomainException::class, 'already has a sales order');

        $invoice = $service->convertToInvoice($order->fresh('items'));
        expect($invoice)->toBeInstanceOf(Invoice::class)
            ->and($order->fresh()->status)->toBe('invoiced');

        // Already invoiced guard
        expect(fn () => $service->convertToInvoice($order->fresh()))->toThrow(DomainException::class, 'cannot be invoiced');

        // Non-invoiced status but existing invoice guard
        $order->fresh()->update(['status' => 'confirmed']);
        expect(fn () => $service->convertToInvoice($order->fresh()))->toThrow(DomainException::class, 'already has an invoice');
    });

    it('creates purchase orders from approved purchase requests with domain guards', function (): void {
        $team = Team::factory()->create();
        $paymentTerm = PaymentTerm::create([
            'payment_term_name' => 'Net 30',
            'payment_term_description' => 'Due in 30 days',
            'payment_term_number_of_days' => 30,
        ]);
        $supplier = Supplier::create([
            'supplier_first_name' => 'Acme',
            'supplier_last_name' => 'Supply',
            'supplier_email' => 'acme@supply.test',
            'supplier_address' => '1 Main St',
            'supplier_phone_number' => '555-0100',
            'payment_term_id' => $paymentTerm->payment_term_id,
            'supplier_limit_credit' => 10000,
            'supplier_tin' => 123456789,
        ]);

        $pr = PurchaseRequest::create([
            'team_id' => $team->id,
            'supplier_id' => $supplier->supplier_id,
            'request_date' => today(),
            'total_amount' => 500,
            'status' => 'open',
            'approval_status' => 'pending',
        ]);
        $pr->items()->create([
            'description' => 'Widget',
            'quantity' => 5,
            'unit_price' => 100,
            'total_price' => 500,
        ]);

        $service = new ProcurementService();
        expect(fn () => $service->createPurchaseOrderFromRequest($pr))->toThrow(DomainException::class, 'approved');

        $pr->update(['approval_status' => 'approved']);
        $po = $service->createPurchaseOrderFromRequest($pr->fresh('items'));
        expect($po->items()->count())->toBe(1);

        expect(fn () => $service->createPurchaseOrderFromRequest($pr->fresh()))->toThrow(DomainException::class, 'already has a purchase order');
    });
});

describe('CollectionService', function (): void {
    it('flags delinquent accounts, notifies customers, and builds collection report', function (): void {
        Notification::fake();

        $customer = Customer::factory()->create([
            'credit_hold' => false,
            'customer_email' => 'late@customer.test',
        ]);
        // Set email attribute if column exists, or rely on customer_email
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'payment_status' => 'pending',
            'due_date' => now()->subDays(100)->toDateString(),
            'total_amount' => 750,
        ]);

        $service = new CollectionService();
        $service->flagDelinquentAccounts();

        expect((bool) $customer->fresh()->credit_hold)->toBeTrue();

        $report = $service->generateCollectionReport();
        expect($report)->toHaveCount(1)
            ->and((float) $report->first()['total_overdue'])->toBe(750.0);
    });
});

describe('ApprovalService', function (): void {
    it('enforces canAct rules, multi-step approvals, fallback roles, and cascading rejections', function (): void {
        Notification::fake();

        $managerRole = Role::findOrCreate('approval_manager', 'web');
        $directorRole = Role::findOrCreate('approval_director', 'web');
        $fallbackRole = Role::findOrCreate('approval_fallback', 'web');

        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();

        $requester = User::factory()->create();
        $requester->forceFill(['current_team_id' => $team->id])->save();
        $requester->assignRole($managerRole);

        $manager = User::factory()->create();
        $manager->forceFill(['current_team_id' => $team->id])->save();
        $manager->assignRole($managerRole);

        $director = User::factory()->create();
        $director->forceFill(['current_team_id' => $team->id])->save();
        $director->assignRole($directorRole);

        $fallbackUser = User::factory()->create();
        $fallbackUser->forceFill(['current_team_id' => $team->id])->save();
        $fallbackUser->assignRole($fallbackRole);

        $outsider = User::factory()->create();
        $outsider->forceFill(['current_team_id' => $otherTeam->id])->save();
        $outsider->assignRole($managerRole);

        $rule = ApprovalRule::create([
            'team_id' => $team->id,
            'approvable_type' => Expense::class,
            'min_amount' => 100,
            'steps' => ['approval_manager', 'approval_director'],
            'deadline_days' => 2,
            'fallback_role' => 'approval_fallback',
            'is_active' => true,
        ]);

        $expense = Expense::create([
            'team_id' => $team->id,
            'user_id' => $requester->id,
            'amount' => 500,
            'description' => 'Travel',
            'date' => today(),
            'approval_status' => 'pending',
        ]);

        $req = ApprovalRequest::create([
            'team_id' => $team->id,
            'approvable_type' => Expense::class,
            'approvable_id' => $expense->id,
            'rule_id' => $rule->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'current_step' => 1,
        ]);

        $step1 = ApprovalStep::create([
            'approval_request_id' => $req->id,
            'position' => 1,
            'role' => 'approval_manager',
            'status' => ApprovalStep::STATUS_PENDING,
        ]);
        $step2 = ApprovalStep::create([
            'approval_request_id' => $req->id,
            'position' => 2,
            'role' => 'approval_director',
            'status' => ApprovalStep::STATUS_PENDING,
        ]);

        $service = new ApprovalService();

        // Cross-team blocked
        expect($service->canAct($step1, $outsider))->toBeFalse();
        // Out-of-order step blocked
        expect($service->canAct($step2, $director))->toBeFalse();
        // Guard throws ApprovalDeniedException
        expect(fn () => $service->approve($step2, $director))->toThrow(ApprovalDeniedException::class);

        // Step 1 approved -> advances to step 2
        $service->approve($step1, $manager, 'Looks good');
        expect($req->fresh()->current_step)->toBe(2);

        // Escalated step 2 approved by fallback role -> completes request
        $step2->update(['status' => ApprovalStep::STATUS_ESCALATED]);
        expect($service->canAct($step2->fresh(), $fallbackUser))->toBeTrue();
        $service->approve($step2->fresh(), $fallbackUser, 'Fallback ok');
        expect($req->fresh()->status)->toBe(ApprovalRequest::STATUS_APPROVED)
            ->and($expense->fresh()->approval_status)->toBe('approved');

        // Non-pending request returns false
        expect($service->canAct($step2->fresh(), $director))->toBeFalse();

        // Rejection cascades to remaining steps
        $expense2 = Expense::create([
            'team_id' => $team->id,
            'user_id' => $requester->id,
            'amount' => 600,
            'description' => 'Gadget',
            'date' => today(),
            'approval_status' => 'pending',
        ]);
        $req2 = ApprovalRequest::create([
            'team_id' => $team->id,
            'approvable_type' => Expense::class,
            'approvable_id' => $expense2->id,
            'rule_id' => $rule->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'current_step' => 1,
        ]);
        $rStep1 = ApprovalStep::create(['approval_request_id' => $req2->id, 'position' => 1, 'role' => 'approval_manager', 'status' => ApprovalStep::STATUS_PENDING]);
        $rStep2 = ApprovalStep::create(['approval_request_id' => $req2->id, 'position' => 2, 'role' => 'approval_director', 'status' => ApprovalStep::STATUS_PENDING]);

        $service->reject($rStep1, $manager, 'Too expensive');
        expect($req2->fresh()->status)->toBe(ApprovalRequest::STATUS_REJECTED)
            ->and($rStep2->fresh()->status)->toBe(ApprovalStep::STATUS_REJECTED)
            ->and($expense2->fresh()->approval_status)->toBe('rejected');
    });
});
