<?php

declare(strict_types=1);

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Response;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\Bill;
use Liberu\Accounting\Erp\Models\Budget;
use Liberu\Accounting\Erp\Models\Category;
use Liberu\Accounting\Erp\Models\Currency;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\Employee;
use Liberu\Accounting\Erp\Models\Estimate;
use Liberu\Accounting\Erp\Models\ExchangeRate;
use Liberu\Accounting\Erp\Models\Expense;
use Liberu\Accounting\Erp\Models\InventoryCostLayer;
use Liberu\Accounting\Erp\Models\InventoryItem;
use Liberu\Accounting\Erp\Models\InventoryTransaction;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\InvoiceItem;
use Liberu\Accounting\Erp\Models\JournalEntry;
use Liberu\Accounting\Erp\Models\JournalEntryLine;
use Liberu\Accounting\Erp\Models\Membership;
use Liberu\Accounting\Erp\Models\Menu;
use Liberu\Accounting\Erp\Models\OutboundPayment;
use Liberu\Accounting\Erp\Models\Payment;
use Liberu\Accounting\Erp\Models\PaymentTerm;
use Liberu\Accounting\Erp\Models\Payroll;
use Liberu\Accounting\Erp\Models\Plan;
use Liberu\Accounting\Erp\Models\PremiumCatalog;
use Liberu\Accounting\Erp\Models\Project;
use Liberu\Accounting\Erp\Models\PurchaseOrder;
use Liberu\Accounting\Erp\Models\PurchaseOrderItem;
use Liberu\Accounting\Erp\Models\PurchaseRequest;
use Liberu\Accounting\Erp\Models\PurchaseRequestItem;
use Liberu\Accounting\Erp\Models\QboConnection;
use Liberu\Accounting\Erp\Models\ReconciliationRule;
use Liberu\Accounting\Erp\Models\RefundReceipt;
use Liberu\Accounting\Erp\Models\RefundReceiptItem;
use Liberu\Accounting\Erp\Models\ReminderSetting;
use Liberu\Accounting\Erp\Models\RevenueSchedule;
use Liberu\Accounting\Erp\Models\RevenueScheduleEntry;
use Liberu\Accounting\Erp\Models\Role;
use Liberu\Accounting\Erp\Models\SageConnection;
use Liberu\Accounting\Erp\Models\SalesOrder;
use Liberu\Accounting\Erp\Models\SalesOrderItem;
use Liberu\Accounting\Erp\Models\SalesReceipt;
use Liberu\Accounting\Erp\Models\SalesReceiptItem;
use Liberu\Accounting\Erp\Models\Subscription;
use Liberu\Accounting\Erp\Models\Supplier;
use Liberu\Accounting\Erp\Models\TaxForm;
use Liberu\Accounting\Erp\Models\TaxRate;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\TeamInvitation;
use Liberu\Accounting\Erp\Models\TimeEntry;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Models\UserNotificationPreference;
use Liberu\Accounting\Erp\Models\Vendor;
use Liberu\Accounting\Erp\Models\VendorCredit;
use Liberu\Accounting\Erp\Models\VendorCreditApplication;
use Liberu\Accounting\Erp\Models\VendorCreditItem;
use Liberu\Accounting\Erp\Models\XeroConnection;

function makePart2UserAndTeam(): array
{
    $user = User::factory()->create();
    $team = $user->ownedTeams()->create([
        'name' => $user->name."'s Team",
        'personal_team' => true,
    ]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $user->refresh();

    return [$user, $team];
}

function makePart2Customer(int $teamId, int $seq = 1): Customer
{
    return Customer::create([
        'customer_name' => 'Jane'.$seq,
        'customer_last_name' => 'Smith'.$seq,
        'customer_address' => '10 High St',
        'customer_email' => "p2cust{$seq}_".uniqid().'@example.com',
        'customer_phone' => '555-200'.random_int(1000, 9999).$seq,
        'customer_city' => 'Manchester',
        'team_id' => $teamId,
    ]);
}

function makePart2Vendor(int $teamId, int $seq = 1): Vendor
{
    return Vendor::create([
        'name' => 'Part2 Vendor '.$seq,
        'email' => "p2vendor{$seq}_".uniqid().'@example.com',
        'phone' => '555-3000',
        'address' => '99 Market St',
        'payment_terms' => 30,
        'status' => 'active',
        'team_id' => $teamId,
    ]);
}

// =========================================================================
// 1. InventoryItem, InventoryCostLayer, InventoryTransaction
// =========================================================================

test('Inventory models cover relationships, updateQuantity, and needsReorder', function () {
    [$user, $team] = makePart2UserAndTeam();
    $account = Account::create([
        'account_number' => 1400,
        'account_name' => 'Merchandise Inventory',
        'account_type' => 'asset',
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);
    $category = Category::create(['name' => 'Hardware']);

    $item = InventoryItem::create([
        'name' => 'Widget A',
        'sku' => 'WID-A-001',
        'unit_price' => 25.00,
        'current_quantity' => 10,
        'reorder_point' => 5,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'valuation_method' => 'fifo',
        'team_id' => $team->id,
    ]);

    $layer = InventoryCostLayer::create([
        'inventory_item_id' => $item->id,
        'quantity' => 10,
        'unit_cost' => 12.50,
        'purchase_date' => now()->toDateString(),
        'team_id' => $team->id,
    ]);

    $tx = Transaction::create([
        'transaction_date' => now()->toDateString(),
        'amount' => 125.00,
        'transaction_type' => 'purchase',
        'account_id' => $account->id,
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);

    $invTx = InventoryTransaction::create([
        'inventory_item_id' => $item->id,
        'transaction_id' => $tx->transaction_id,
        'quantity' => 5,
        'unit_price' => 12.50,
        'transaction_type' => 'purchase',
        'team_id' => $team->id,
    ]);

    expect($item->account->id)->toBe($account->id)
        ->and($item->category->id)->toBe($category->id)
        ->and($item->billOfMaterials())->toBeInstanceOf(HasMany::class)
        ->and($item->transactions)->toHaveCount(1)
        ->and($layer->inventoryItem->id)->toBe($item->id)
        ->and($invTx->inventoryItem->id)->toBe($item->id)
        ->and($invTx->transaction->transaction_id)->toBe($tx->transaction_id)
        ->and($item->needsReorder())->toBeFalse();

    $item->updateQuantity(-6);
    expect($item->fresh()->current_quantity)->toBe(4)
        ->and($item->fresh()->needsReorder())->toBeTrue();
});

// =========================================================================
// 2. Invoice, InvoiceItem, Payment, TimeEntry
// =========================================================================

test('Invoice, InvoiceItem, Payment, and TimeEntry cover boot, totals, payment status, late fee, PDF, approval, and recurrence', function () {
    [$user, $team] = makePart2UserAndTeam();
    $this->actingAs($user);

    $customer = makePart2Customer($team->id);
    $vendor = makePart2Vendor($team->id);
    $account = Account::create([
        'account_number' => 4100,
        'account_name' => 'Sales Revenue',
        'account_type' => 'revenue',
    ]);
    $taxRate = TaxRate::create([
        'name' => 'Standard 20%',
        'rate' => 20.0,
        'is_compound' => false,
        'is_active' => true,
        'team_id' => $team->id,
    ]);
    $je = JournalEntry::create([
        'entry_date' => now()->toDateString(),
        'memo' => 'Posted payment JE',
        'team_id' => $team->id,
    ]);

    $invoice = Invoice::create([
        'customer_id' => $customer->id,
        'vendor_id' => $vendor->vendor_id,
        'invoice_date' => now()->subDays(20)->toDateString(),
        'due_date' => now()->subDays(10)->toDateString(),
        'total_amount' => 0,
        'payment_status' => 'pending',
        'journal_entry_id' => $je->id,
        'team_id' => $team->id,
    ]);
    $invoice->forceFill([
        'late_fee_percentage' => 5.0,
        'grace_period_days' => 2,
    ])->save();

    expect($invoice->invoice_number)->toStartWith('INV-')
        ->and($invoice->approval_status)->toBe('pending')
        ->and($invoice->customer->id)->toBe($customer->id)
        ->and($invoice->vendor->vendor_id)->toBe($vendor->vendor_id)
        ->and($invoice->salesOrder())->toBeInstanceOf(BelongsTo::class)
        ->and($invoice->approver())->toBeInstanceOf(BelongsTo::class)
        ->and($invoice->journalEntry->id)->toBe($je->id)
        ->and($invoice->creditMemos())->toBeInstanceOf(HasMany::class);

    // InvoiceItem saving/saved/deleted hooks + getTotalWithTax
    $item1 = InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'account_id' => $account->id,
        'description' => 'Service A',
        'quantity' => 2,
        'unit_price' => 100.00,
        'tax_rate_id' => $taxRate->tax_rate_id,
    ]);
    $item2 = InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'account_id' => $account->id,
        'description' => 'Service B',
        'quantity' => 1,
        'unit_price' => 50.00,
    ]);
    expect($item1->invoice->id)->toBe($invoice->id)
        ->and($item1->account->id)->toBe($account->id)
        ->and($item1->taxRate->tax_rate_id)->toBe($taxRate->tax_rate_id);

    $item2->delete();
    $invoice->refresh();
    expect((float) $invoice->total_amount)->toBe(200.0)
        ->and($invoice->getTotalWithTax())->toBe(240.0);

    // Late fee: overdue, within grace period, and paid branches
    expect($invoice->calculateLateFee())->toBe(10.0);
    $invoice->forceFill(['due_date' => now()->addDays(5)])->save();
    expect($invoice->calculateLateFee())->toBe(0.0);

    // recomputePaymentStatus: pending -> partial -> paid
    $invoice->recomputePaymentStatus();
    expect($invoice->fresh()->payment_status)->toBe('pending');

    $pay1 = Payment::create([
        'invoice_id' => $invoice->id,
        'payment_date' => now()->toDateString(),
        'payment_amount' => 100.00,
        'journal_entry_id' => $je->id,
        'team_id' => $team->id,
    ]);
    expect($pay1->invoice->id)->toBe($invoice->id)
        ->and($pay1->journalEntry->id)->toBe($je->id);

    $invoice->recomputePaymentStatus();
    expect($invoice->fresh()->payment_status)->toBe('partial');

    Payment::create([
        'invoice_id' => $invoice->id,
        'payment_date' => now()->toDateString(),
        'payment_amount' => 100.00,
        'journal_entry_id' => $je->id,
        'team_id' => $team->id,
    ]);
    $invoice->recomputePaymentStatus();
    expect($invoice->fresh()->payment_status)->toBe('paid')
        ->and($invoice->fresh()->calculateLateFee())->toBe(0.0);

    // TimeEntry & calculateTotalFromTimeEntries
    $te = TimeEntry::create([
        'customer_id' => $customer->id,
        'invoice_id' => $invoice->id,
        'start_time' => now()->subHours(3),
        'end_time' => now(),
        'description' => 'Consulting',
        'hourly_rate' => 100.00,
        'total_amount' => 0,
        'team_id' => $team->id,
    ]);
    expect($te->customer->id)->toBe($customer->id)
        ->and($te->invoice->id)->toBe($invoice->id);
    $te->calculateTotalAmount();
    $te->save();
    $invoice->load('timeEntries');
    expect((float) $invoice->calculateTotalFromTimeEntries())->toBe((float) $te->total_amount);

    // generatePDF via mocked Pdf facade
    $pdfMock = Mockery::mock();
    $pdfMock->shouldReceive('download')->once()->andReturn(new Response('pdf-bytes'));
    Pdf::shouldReceive('loadView')->once()->andReturn($pdfMock);
    expect($invoice->generatePDF())->toBeInstanceOf(Response::class);

    // Approval helpers
    expect($invoice->approvalAmount())->toBe((float) $invoice->total_amount)
        ->and($invoice->isPending())->toBeTrue();
    $invoice->approve();
    expect($invoice->fresh()->approval_status)->toBe('approved');
    $invoice->reject('Incorrect PO');
    expect($invoice->fresh()->approval_status)->toBe('rejected');

    // Recurrence hooks
    $recInv = Invoice::create([
        'customer_id' => $customer->id,
        'invoice_date' => today(),
        'due_date' => today()->addDays(30),
        'total_amount' => 300.00,
        'payment_status' => 'pending',
        'is_recurring' => true,
        'recurrence_frequency' => 'monthly',
        'recurrence_start' => today(),
        'team_id' => $team->id,
    ]);
    expect($recInv->generateDue())->toBe(1);
});

// =========================================================================
// 3. JournalEntry & JournalEntryLine
// =========================================================================

test('JournalEntry and JournalEntryLine cover boot hooks, period lock, relationships, post, reverse, and errors', function () {
    [$user, $team] = makePart2UserAndTeam();
    [, $otherTeam] = makePart2UserAndTeam();
    $this->actingAs($user);

    $debitAcc = Account::create([
        'account_number' => 1011,
        'account_name' => 'Cash JE',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'balance' => 100.00,
    ]);
    $creditAcc = Account::create([
        'account_number' => 4011,
        'account_name' => 'Revenue JE',
        'account_type' => 'revenue',
        'normal_balance' => 'credit',
        'balance' => 50.00,
    ]);

    // Auto-stamps user_id, team_id, entry_number
    $je = JournalEntry::create([
        'entry_date' => now()->toDateString(),
        'memo' => 'Opening',
        'counterparty_team_id' => $otherTeam->id,
    ]);

    expect($je->user_id)->toBe($user->id)
        ->and($je->team_id)->toBe($team->id)
        ->and($je->entry_number)->toStartWith('JE-')
        ->and($je->user->id)->toBe($user->id)
        ->and($je->approvedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($je->counterpartyTeam->id)->toBe($otherTeam->id);

    $debitLine = JournalEntryLine::create([
        'journal_entry_id' => $je->id,
        'account_id' => $debitAcc->id,
        'debit_amount' => 250.00,
        'credit_amount' => 0,
        'team_id' => $team->id,
    ]);

    expect($debitLine->journalEntry->id)->toBe($je->id)
        ->and($debitLine->account->id)->toBe($debitAcc->id)
        ->and((float) $debitLine->amount)->toBe(250.0)
        ->and($debitLine->type)->toBe('debit')
        ->and($je->isBalanced())->toBeFalse()
        ->and(fn () => $je->post())->toThrow(\Exception::class, 'must be balanced')
        ->and(fn () => $je->reverse())->toThrow(\Exception::class, 'Cannot reverse an unposted');

    $creditLine = JournalEntryLine::create([
        'journal_entry_id' => $je->id,
        'account_id' => $creditAcc->id,
        'debit_amount' => 0,
        'credit_amount' => 250.00,
        'team_id' => $team->id,
    ]);
    expect((float) $creditLine->amount)->toBe(250.0)
        ->and($creditLine->type)->toBe('credit')
        ->and($je->approvalAmount())->toBe(250.0)
        ->and($je->isBalanced())->toBeTrue();

    // Post & already posted guard & reverse
    $je->post();
    expect($je->is_posted)->toBeTrue()
        ->and((float) $debitAcc->fresh()->balance)->toBe(350.0)
        ->and((float) $creditAcc->fresh()->balance)->toBe(300.0)
        ->and(fn () => $je->post())->toThrow(\Exception::class, 'already posted');

    $je->reverse();
    expect($je->is_posted)->toBeFalse()
        ->and((float) $debitAcc->fresh()->balance)->toBe(100.0)
        ->and((float) $creditAcc->fresh()->balance)->toBe(50.0);

    // Second entry triggers increment branch in generateEntryNumber
    $je2 = JournalEntry::create(['entry_date' => now()->toDateString()]);
    expect($je2->entry_number)->not->toBe($je->entry_number);

    // Period close lock guard throws DomainException
    $team->update(['books_locked_before' => now()->toDateString()]);
    $user->refresh();
    expect(fn () => JournalEntry::create([
        'entry_date' => now()->subDays(5)->toDateString(),
        'team_id' => $team->id,
    ]))->toThrow(\DomainException::class, 'books are closed');
});

// =========================================================================
// 4. Membership, Menu, OutboundPayment, PaymentTerm, Payroll, Plan, PremiumCatalog, Project
// =========================================================================

test('Membership, Menu, OutboundPayment, PaymentTerm, PremiumCatalog, ReminderSetting, and Role work as expected', function () {
    [$user, $team] = makePart2UserAndTeam();

    $membership = new Membership();
    expect($membership->incrementing)->toBeTrue();

    $parentMenu = Menu::create(['name' => 'Accounting', 'url' => '/accounting', 'order' => 1, 'team_id' => $team->id]);
    $childMenu = Menu::create(['name' => 'Invoices', 'url' => '/invoices', 'parent_id' => $parentMenu->id, 'order' => 2, 'team_id' => $team->id]);
    expect($childMenu->parent->id)->toBe($parentMenu->id)
        ->and($parentMenu->children)->toHaveCount(1);

    $bankConn = BankConnection::create(['bank_id' => 'rev', 'institution_name' => 'Revolut', 'user_id' => $user->id, 'team_id' => $team->id]);
    $outbound = OutboundPayment::create([
        'team_id' => $team->id,
        'bank_connection_id' => $bankConn->id,
        'requested_by' => $user->id,
        'account_id' => 'acc_123',
        'receiver' => ['name' => 'Acme'],
        'amount' => 750.00,
        'currency' => 'GBP',
        'reference' => 'INV-100',
        'idempotency_key' => 'idem-'.uniqid(),
    ]);
    expect($outbound->approvalAmount())->toBe(750.0)
        ->and($outbound->bankConnection->id)->toBe($bankConn->id);

    $pt = PaymentTerm::create([
        'payment_term_name' => 'Net 15',
        'payment_term_description' => 'Due in 15 days',
        'payment_term_number_of_days' => 15,
    ]);
    expect($pt->suppliers())->toBeInstanceOf(HasMany::class);

    $catalog = PremiumCatalog::create([
        'catalog_key' => 'pro_'.uniqid(),
        'stripe_product_id' => 'prod_'.uniqid(),
        'stripe_monthly_price_id' => 'price_m_'.uniqid(),
        'stripe_yearly_price_id' => 'price_y_'.uniqid(),
    ]);
    expect($catalog->exists)->toBeTrue();

    $reminder = ReminderSetting::create([
        'days_before_reminder' => 3,
        'reminder_frequency_days' => 7,
        'max_reminders' => 3,
        'is_active' => true,
        'team_id' => $team->id,
    ]);
    expect($reminder->is_active)->toBeTrue();

    $role = Role::create(['name' => 'accountant_'.uniqid(), 'guard_name' => 'web']);
    expect($role->exists)->toBeTrue();
});

test('Payroll covers employee relation, grossSalary, calculateNetSalary, and totalDeductions', function () {
    [, $team] = makePart2UserAndTeam();
    $emp = Employee::create([
        'name' => 'Carol Dev',
        'email' => 'carol@example.com',
        'position' => 'Senior Dev',
        'hire_date' => '2022-01-01',
        'tax_id' => 'TAX-CAROL',
        'has_student_loan' => true,
        'student_loan_plan' => 'plan_2',
        'team_id' => $team->id,
    ]);

    $payroll = Payroll::create([
        'employee_id' => $emp->id,
        'base_salary' => 4000.00,
        'overtime_hours' => 5.00,
        'overtime_rate' => 50.00,
        'other_deductions' => 100.00,
        'net_salary' => 0,
        'pay_period_start' => '2025-04-01',
        'pay_period_end' => '2025-04-30',
        'payment_date' => '2025-04-30',
        'team_id' => $team->id,
    ]);

    expect($payroll->employee->id)->toBe($emp->id)
        ->and($payroll->grossSalary())->toBe(4250.0);

    $payroll->calculateNetSalary();
    expect($payroll->totalDeductions())->toBeGreaterThan(100.0)
        ->and((float) $payroll->net_salary)->toBeLessThan(4250.0);
});

test('Plan and Subscription cover boot hooks, relationships, pause, resume, and cancel', function () {
    [$user, $team] = makePart2UserAndTeam();
    $this->actingAs($user);
    $customer = makePart2Customer($team->id);

    $plan = Plan::create([
        'name' => 'Pro Monthly',
        'amount' => 49.99,
        'currency' => 'USD',
        'interval' => 'monthly',
    ]);

    $sub = Subscription::create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'started_at' => now()->subMonth(),
        'next_billing_date' => now()->subDays(2),
    ]);

    expect($plan->team_id)->toBe($team->id)
        ->and($plan->subscriptions)->toHaveCount(1)
        ->and($sub->team_id)->toBe($team->id)
        ->and($sub->customer->id)->toBe($customer->id)
        ->and($sub->plan->id)->toBe($plan->id);

    $sub->pause();
    expect($sub->fresh()->status)->toBe('paused');

    $sub->resume();
    expect($sub->fresh()->status)->toBe('active')
        ->and($sub->fresh()->next_billing_date->toDateString())->toBe(today()->toDateString());

    $sub->cancel();
    expect($sub->fresh()->status)->toBe('cancelled')
        ->and($sub->fresh()->cancelled_at)->not->toBeNull();
});

test('Project covers transactions, expenses, and budgets relationships', function () {
    [, $team] = makePart2UserAndTeam();
    $project = Project::create(['name' => 'ERP Rollout', 'code' => 'ERP', 'team_id' => $team->id]);

    expect($project->transactions())->toBeInstanceOf(HasMany::class)
        ->and($project->expenses())->toBeInstanceOf(HasMany::class)
        ->and($project->budgets())->toBeInstanceOf(HasMany::class);
});

// =========================================================================
// 5. PurchaseOrder, PurchaseOrderItem, PurchaseRequest, PurchaseRequestItem, Supplier
// =========================================================================

test('PurchaseOrder, PurchaseOrderItem, PurchaseRequest, PurchaseRequestItem, and Supplier cover hooks and relationships', function () {
    [$user, $team] = makePart2UserAndTeam();
    $this->actingAs($user);

    $pt = PaymentTerm::create([
        'payment_term_name' => 'Net 30 PO',
        'payment_term_description' => '30 days',
        'payment_term_number_of_days' => 30,
    ]);
    $supplier = Supplier::create([
        'payment_term_id' => $pt->payment_term_id,
        'supplier_first_name' => 'Global',
        'supplier_last_name' => 'Supplies',
        'supplier_email' => 'global@supplies.test',
        'supplier_address' => '1 Warehouse Way',
        'supplier_phone_number' => '555-9999',
        'supplier_limit_credit' => 50000,
        'supplier_tin' => 98765,
        'team_id' => $team->id,
    ]);
    expect($supplier->paymentTerm->payment_term_id)->toBe($pt->payment_term_id);

    $pr = PurchaseRequest::create([
        'supplier_id' => $supplier->supplier_id,
        'request_date' => now()->toDateString(),
        'total_amount' => 500.00,
    ]);
    $prItem = PurchaseRequestItem::create([
        'purchase_request_id' => $pr->id,
        'description' => 'Laptops',
        'quantity' => 2,
        'unit_price' => 250.00,
    ]);

    expect($pr->team_id)->toBe($team->id)
        ->and($pr->request_number)->toStartWith('PR-')
        ->and($pr->approvalAmount())->toBe(500.0)
        ->and($pr->supplier->supplier_id)->toBe($supplier->supplier_id)
        ->and($pr->items)->toHaveCount(1)
        ->and((float) $prItem->total_price)->toBe(500.0)
        ->and($prItem->purchaseRequest->id)->toBe($pr->id);

    $po1Num = PurchaseOrder::generatePoNumber();
    $po = PurchaseOrder::create([
        'supplier_id' => $supplier->supplier_id,
        'purchase_request_id' => $pr->id,
        'po_number' => $po1Num,
        'order_date' => now()->toDateString(),
        'total_amount' => 500.00,
        'status' => 'ordered',
        'team_id' => $team->id,
    ]);
    $poItem = PurchaseOrderItem::create([
        'purchase_order_id' => $po->purchase_order_id,
        'description' => 'Laptops',
        'quantity' => 2,
        'unit_price' => 250.00,
        'total_price' => 500.00,
    ]);

    expect($pr->purchaseOrder->purchase_order_id)->toBe($po->purchase_order_id)
        ->and($po->supplier->supplier_id)->toBe($supplier->supplier_id)
        ->and($po->purchaseRequest->id)->toBe($pr->id)
        ->and($po->items)->toHaveCount(1)
        ->and($po->transactions())->toBeInstanceOf(HasMany::class)
        ->and($po->bills())->toBeInstanceOf(HasMany::class)
        ->and($poItem->purchaseOrder->purchase_order_id)->toBe($po->purchase_order_id)
        ->and(PurchaseOrder::generatePoNumber())->not->toBe($po1Num);
});

// =========================================================================
// 6. QboConnection, SageConnection, XeroConnection, ReconciliationRule
// =========================================================================

test('Accounting provider connections and ReconciliationRule cover relationships', function () {
    [$user, $team] = makePart2UserAndTeam();
    $account = Account::create([
        'account_number' => 5100,
        'account_name' => 'Software',
        'account_type' => 'expense',
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);

    $qbo = QboConnection::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'realm_id' => 'realm-1',
        'access_token' => 'tok-qbo',
        'refresh_token' => 'ref-qbo',
    ]);
    $sage = SageConnection::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'business_id' => 'biz-1',
        'access_token' => 'tok-sage',
        'refresh_token' => 'ref-sage',
    ]);
    $xero = XeroConnection::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'tenant_id' => 'ten-1',
        'access_token' => 'tok-xero',
        'refresh_token' => 'ref-xero',
    ]);
    $rule = ReconciliationRule::create([
        'team_id' => $team->id,
        'name' => 'AWS Rule',
        'match_field' => 'description',
        'match_operator' => 'contains',
        'match_value' => 'AWS',
        'action_account_id' => $account->id,
        'priority' => 1,
        'is_active' => true,
    ]);

    expect($qbo->user->id)->toBe($user->id)
        ->and($sage->user->id)->toBe($user->id)
        ->and($xero->user->id)->toBe($user->id)
        ->and($rule->actionAccount->id)->toBe($account->id);
});

// =========================================================================
// 7. SalesOrder, SalesOrderItem, SalesReceipt, SalesReceiptItem, RefundReceipt, RefundReceiptItem
// =========================================================================

test('SalesOrder and SalesOrderItem cover boot, relationships, confirm, and cancel', function () {
    [$user, $team] = makePart2UserAndTeam();
    $this->actingAs($user);
    $customer = makePart2Customer($team->id);
    $estimate = Estimate::create([
        'customer_id' => $customer->id,
        'estimate_date' => now()->toDateString(),
        'subtotal_amount' => 100.00,
        'total_amount' => 100.00,
        'team_id' => $team->id,
    ]);

    $so = SalesOrder::create([
        'customer_id' => $customer->id,
        'estimate_id' => $estimate->estimate_id,
        'order_date' => now()->toDateString(),
        'subtotal_amount' => 100.00,
        'total_amount' => 100.00,
    ]);
    $soItem = SalesOrderItem::create([
        'sales_order_id' => $so->id,
        'description' => 'Consulting',
        'quantity' => 1,
        'unit_price' => 100.00,
        'amount' => 100.00,
    ]);

    expect($so->team_id)->toBe($team->id)
        ->and($so->sales_order_number)->toStartWith('SO-')
        ->and($so->customer->id)->toBe($customer->id)
        ->and($so->estimate->estimate_id)->toBe($estimate->estimate_id)
        ->and($so->items)->toHaveCount(1)
        ->and($so->invoice())->toBeInstanceOf(HasOne::class)
        ->and($soItem->salesOrder->id)->toBe($so->id);

    $so->confirm();
    expect($so->fresh()->status)->toBe('confirmed');
    $so->cancel();
    expect($so->fresh()->status)->toBe('cancelled');
});

test('SalesReceipt, SalesReceiptItem, RefundReceipt, and RefundReceiptItem cover boot, totals, createRefund, process, and void', function () {
    [$user, $team] = makePart2UserAndTeam();
    $customer = makePart2Customer($team->id);
    $account = Account::create([
        'account_number' => 1020,
        'account_name' => 'Checking Receipt',
        'account_type' => 'asset',
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);
    $taxRate = TaxRate::create([
        'name' => 'Sales Tax 10%',
        'rate' => 10.0,
        'is_active' => true,
        'team_id' => $team->id,
    ]);

    $sr = SalesReceipt::create([
        'customer_id' => $customer->id,
        'sales_receipt_date' => now()->toDateString(),
        'tax_rate_id' => $taxRate->tax_rate_id,
        'payment_method' => 'card',
        'deposit_to_account_id' => $account->id,
        'team_id' => $team->id,
    ]);

    $srItem = SalesReceiptItem::create([
        'sales_receipt_id' => $sr->sales_receipt_id,
        'account_id' => $account->id,
        'description' => 'Product X',
        'quantity' => 2,
        'unit_price' => 50.00,
    ]);

    $sr->load('items');
    $sr->calculateTotals();

    expect($sr->sales_receipt_number)->toStartWith('SR-')
        ->and($sr->status)->toBe('completed')
        ->and($sr->customer->id)->toBe($customer->id)
        ->and($sr->taxRate->tax_rate_id)->toBe($taxRate->tax_rate_id)
        ->and($sr->depositAccount->id)->toBe($account->id)
        ->and($srItem->salesReceipt->sales_receipt_id)->toBe($sr->sales_receipt_id)
        ->and($srItem->account->id)->toBe($account->id)
        ->and((float) $srItem->amount)->toBe(100.0)
        ->and((float) $sr->subtotal_amount)->toBe(100.0)
        ->and((float) $sr->tax_amount)->toBe(10.0)
        ->and((float) $sr->total_amount)->toBe(110.0)
        ->and($sr->isVoid())->toBeFalse();

    // Create refund from sales receipt
    $refund = $sr->createRefund([
        ['description' => 'Return X', 'quantity' => 1, 'unit_price' => 50.00, 'account_id' => $account->id],
    ], 'product_return');

    $refundItem = $refund->items()->first();
    expect($refund->refund_receipt_number)->toStartWith('RR-')
        ->and($refund->customer->id)->toBe($customer->id)
        ->and($refund->salesReceipt->sales_receipt_id)->toBe($sr->sales_receipt_id)
        ->and($refund->invoice())->toBeInstanceOf(BelongsTo::class)
        ->and($refund->refundAccount())->toBeInstanceOf(BelongsTo::class)
        ->and($refundItem->refundReceipt->refund_receipt_id)->toBe($refund->refund_receipt_id)
        ->and($refundItem->account->id)->toBe($account->id)
        ->and((float) $refund->subtotal_amount)->toBe(50.0)
        ->and((float) $refund->tax_amount)->toBe(5.0)
        ->and((float) $refund->total_amount)->toBe(55.0)
        ->and($refund->isCompleted())->toBeFalse();

    $refund->process();
    expect($refund->isCompleted())->toBeTrue();
    $refund->void();
    expect($refund->status)->toBe('void');

    $sr->void();
    expect($sr->isVoid())->toBeTrue();
});

// =========================================================================
// 8. RevenueSchedule & RevenueScheduleEntry
// =========================================================================

test('RevenueSchedule and RevenueScheduleEntry cover boot hook and relationships', function () {
    [$user, $team] = makePart2UserAndTeam();
    $this->actingAs($user);
    $customer = makePart2Customer($team->id);
    $invoice = Invoice::create([
        'customer_id' => $customer->id,
        'invoice_date' => now(),
        'total_amount' => 1200.00,
        'payment_status' => 'paid',
        'team_id' => $team->id,
    ]);
    $deferred = Account::create(['account_number' => 2400, 'account_name' => 'Deferred Rev', 'account_type' => 'liability']);
    $revenue = Account::create(['account_number' => 4400, 'account_name' => 'Earned Rev', 'account_type' => 'revenue']);

    $schedule = RevenueSchedule::create([
        'invoice_id' => $invoice->id,
        'total_amount' => 1200.00,
        'start_date' => now()->toDateString(),
        'periods' => 12,
        'deferred_account_id' => $deferred->id,
        'revenue_account_id' => $revenue->id,
    ]);
    $entry = RevenueScheduleEntry::create([
        'revenue_schedule_id' => $schedule->id,
        'period_number' => 1,
        'recognition_date' => now()->toDateString(),
        'amount' => 100.00,
        'recognized' => false,
    ]);

    expect($schedule->team_id)->toBe($team->id)
        ->and($schedule->invoice->id)->toBe($invoice->id)
        ->and($schedule->deferredAccount->id)->toBe($deferred->id)
        ->and($schedule->revenueAccount->id)->toBe($revenue->id)
        ->and($schedule->entries)->toHaveCount(1)
        ->and($entry->schedule->id)->toBe($schedule->id)
        ->and($entry->journalEntry())->toBeInstanceOf(BelongsTo::class);
});

// =========================================================================
// 9. TaxForm & TaxRate
// =========================================================================

test('TaxForm and TaxRate cover relationships, tax calculation, summary accessor, and PDF generation', function () {
    [, $team] = makePart2UserAndTeam();
    $customer = makePart2Customer($team->id);

    $form = TaxForm::create([
        'form_type' => '1099-MISC',
        'customer_id' => $customer->id,
        'tax_year' => 2025,
        'total_payments' => 5000.00,
        'total_tax_withheld' => 500.00,
        'status' => 'draft',
        'form_data' => ['tax_summary' => ['Federal' => ['rate' => 10, 'amount' => 500]]],
        'team_id' => $team->id,
    ]);

    $pdfMock = Mockery::mock();
    $pdfMock->shouldReceive('download')->once()->andReturn(new Response('tax-pdf'));
    Pdf::shouldReceive('loadView')->once()->andReturn($pdfMock);

    expect($form->customer->id)->toBe($customer->id)
        ->and($form->tax_summary)->toHaveKey('Federal')
        ->and($form->generatePDF())->toBeInstanceOf(Response::class);

    $active = TaxRate::create(['name' => 'VAT', 'rate' => 20.0, 'is_compound' => false, 'is_active' => true, 'team_id' => $team->id]);
    $compound = TaxRate::create(['name' => 'Comp', 'rate' => 10.0, 'is_compound' => true, 'is_active' => true, 'team_id' => $team->id]);
    $inactive = TaxRate::create(['name' => 'Off', 'rate' => 20.0, 'is_compound' => false, 'is_active' => false, 'team_id' => $team->id]);

    expect($active->calculateTax(100.0))->toBe(20.0)
        ->and($compound->calculateTax(100.0, 20.0))->toBe(12.0)
        ->and($inactive->calculateTax(100.0))->toBe(0)
        ->and($active->invoices())->toBeInstanceOf(HasMany::class)
        ->and($active->customers())->toBeInstanceOf(BelongsToMany::class)
        ->and($active->suppliers())->toBeInstanceOf(BelongsToMany::class);
});

// =========================================================================
// 10. Team, TeamInvitation, User, UserNotificationPreference
// =========================================================================

test('Team and TeamInvitation cover premium access, trial checks, and relationships', function () {
    [$user, $team] = makePart2UserAndTeam();

    config()->set('premium.enabled', false);
    expect($team->hasPremiumAccess())->toBeFalse();

    config()->set('premium.enabled', true);
    $team->update([
        'premium_status' => 'trialing',
        'premium_trial_ends_at' => now()->addDays(7),
    ]);
    expect($team->hasPremiumAccess())->toBeTrue()
        ->and($team->isPremiumTrial())->toBeTrue();

    $team->update([
        'premium_status' => 'active',
        'premium_current_period_ends_at' => now()->addMonth(),
    ]);
    expect($team->hasPremiumAccess())->toBeTrue()
        ->and($team->isPremiumTrial())->toBeFalse();

    $invitation = $team->teamInvitations()->create(['email' => 'invitee@example.com']);
    expect($invitation)->toBeInstanceOf(TeamInvitation::class)
        ->and($invitation->team->id)->toBe($team->id)
        ->and($team->owner->id)->toBe($user->id)
        ->and($team->users())->toBeInstanceOf(BelongsToMany::class)
        ->and($team->categories())->toBeInstanceOf(HasMany::class);
});

test('User and UserNotificationPreference cover profilePhotoUrl, panels, tenants, canCreateTeams, activitylog, and admin checks', function () {
    [$user, $team] = makePart2UserAndTeam();

    $pref = UserNotificationPreference::create([
        'user_id' => $user->id,
        'phone' => '+447700900000',
        'mail_enabled' => true,
        'database_enabled' => true,
        'sms_enabled' => true,
    ]);

    expect($pref->user->id)->toBe($user->id)
        ->and($user->notificationPreference->id)->toBe($pref->id)
        ->and($user->ownedTeams)->toHaveCount(1)
        ->and($user->latestTeam->id)->toBe($team->id);

    // profilePhotoUrl: external URL vs fallback
    $user->profile_photo_path = 'https://cdn.example.com/photo.png';
    expect($user->profile_photo_url)->toBe('https://cdn.example.com/photo.png');
    $user->profile_photo_path = null;
    expect($user->profile_photo_url)->toBeString();

    $adminPanel = Panel::make()->id('admin');
    $appPanel = Panel::make()->id('app');

    expect($user->getTenants($appPanel))->toHaveCount(1)
        ->and($user->canAccessTenant($team))->toBeTrue()
        ->and($user->getDefaultTenant($appPanel)->id)->toBe($team->id)
        ->and($user->canAccessPanel($appPanel))->toBeTrue()
        ->and($user->canAccessPanel($adminPanel))->toBeFalse()
        ->and($user->canCreateTeams())->toBeBool()
        ->and($user->getActivitylogOptions()->logAttributes)->toContain('name');

    config()->set('app.admin_emails', [$user->email]);
    expect($user->isAdmin())->toBeTrue();
});

// =========================================================================
// 11. Transaction
// =========================================================================

test('Transaction covers boot exchange rate lookup, relationships, currency conversions, and updateInventory', function () {
    [$user, $team] = makePart2UserAndTeam();
    $this->actingAs($user);

    $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'is_default' => true]);
    $gbp = Currency::create(['code' => 'GBP', 'name' => 'Pound', 'symbol' => '£', 'is_default' => false]);
    ExchangeRate::create([
        'from_currency_id' => $gbp->currency_id,
        'to_currency_id' => $usd->currency_id,
        'rate' => 1.25,
        'date' => now()->toDateString(),
    ]);
    ExchangeRate::create([
        'from_currency_id' => $usd->currency_id,
        'to_currency_id' => $gbp->currency_id,
        'rate' => 0.80,
        'date' => now()->toDateString(),
    ]);

    $debitAcc = Account::create(['account_number' => 1090, 'account_name' => 'Bank Tx', 'account_type' => 'asset']);
    $creditAcc = Account::create(['account_number' => 4090, 'account_name' => 'Sales Tx', 'account_type' => 'revenue']);
    $bankConn = BankConnection::create(['bank_id' => 'tx_bank', 'institution_name' => 'Monzo']);

    $tx = Transaction::create([
        'transaction_date' => now()->toDateString(),
        'amount' => 100.00,
        'currency_id' => $gbp->currency_id,
        'account_id' => $debitAcc->id,
        'debit_account_id' => $debitAcc->id,
        'credit_account_id' => $creditAcc->id,
        'bank_connection_id' => $bankConn->id,
        'transaction_type' => 'sale',
    ]);

    expect($tx->user_id)->toBe($user->id)
        ->and($tx->team_id)->toBe($team->id)
        ->and((float) $tx->exchange_rate)->toBe(1.25)
        ->and($tx->currency->currency_id)->toBe($gbp->currency_id)
        ->and($tx->account->id)->toBe($debitAcc->id)
        ->and($tx->debitAccount->id)->toBe($debitAcc->id)
        ->and($tx->creditAccount->id)->toBe($creditAcc->id)
        ->and($tx->bankConnection->id)->toBe($bankConn->id)
        ->and($tx->categories())->toBeInstanceOf(BelongsToMany::class)
        ->and($tx->auditLogs())->toBeInstanceOf(MorphMany::class)
        ->and((float) $tx->getAmountInCurrency($gbp))->toBe(100.0)
        ->and((float) $tx->getAmountInDefaultCurrency())->toBe(125.0);

    // updateInventory with sale subtracts quantity
    $invItem = InventoryItem::create([
        'name' => 'Gadget',
        'sku' => 'GAD-1',
        'unit_price' => 50.00,
        'current_quantity' => 20,
        'account_id' => $debitAcc->id,
        'team_id' => $team->id,
    ]);
    InventoryTransaction::create([
        'inventory_item_id' => $invItem->id,
        'transaction_id' => $tx->transaction_id,
        'quantity' => 3,
        'unit_price' => 50.00,
        'transaction_type' => 'sale',
        'team_id' => $team->id,
    ]);

    $tx->load('inventoryTransactions.inventoryItem');
    $tx->updateInventory();
    expect($invItem->fresh()->current_quantity)->toBe(17);
});

// =========================================================================
// 12. Vendor, VendorCredit, VendorCreditApplication, VendorCreditItem
// =========================================================================

test('Vendor, VendorCredit, VendorCreditApplication, and VendorCreditItem cover panel access, boot hooks, totals, applyToBill, and void', function () {
    [$user, $team] = makePart2UserAndTeam();
    $vendor = makePart2Vendor($team->id);
    $account = Account::create([
        'account_number' => 5200,
        'account_name' => 'Purchases',
        'account_type' => 'expense',
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);
    $taxRate = TaxRate::create(['name' => 'VAT 10%', 'rate' => 10.0, 'is_active' => true, 'team_id' => $team->id]);

    $vendorPanel = Panel::make()->id('vendor');
    $adminPanel = Panel::make()->id('admin');
    expect($vendor->canAccessPanel($vendorPanel))->toBeTrue()
        ->and($vendor->canAccessPanel($adminPanel))->toBeFalse()
        ->and($vendor->invoices())->toBeInstanceOf(HasMany::class)
        ->and($vendor->bills())->toBeInstanceOf(HasMany::class)
        ->and($vendor->vendorCredits())->toBeInstanceOf(HasMany::class);

    $bill = Bill::create([
        'vendor_id' => $vendor->vendor_id,
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal_amount' => 300.00,
        'total_amount' => 300.00,
        'team_id' => $team->id,
    ]);

    $vc = VendorCredit::create([
        'vendor_id' => $vendor->vendor_id,
        'credit_date' => now()->toDateString(),
        'bill_id' => $bill->bill_id,
        'tax_rate_id' => $taxRate->tax_rate_id,
        'team_id' => $team->id,
    ]);

    $vcItem = VendorCreditItem::create([
        'vendor_credit_id' => $vc->vendor_credit_id,
        'account_id' => $account->id,
        'description' => 'Defective units',
        'quantity' => 2,
        'unit_price' => 50.00,
    ]);

    $vc->load('items');
    $vc->calculateTotals();

    expect($vc->vendor_credit_number)->toStartWith('VC-')
        ->and($vc->vendor->vendor_id)->toBe($vendor->vendor_id)
        ->and($vc->bill->bill_id)->toBe($bill->bill_id)
        ->and($vc->taxRate->tax_rate_id)->toBe($taxRate->tax_rate_id)
        ->and($vcItem->vendorCredit->vendor_credit_id)->toBe($vc->vendor_credit_id)
        ->and($vcItem->account->id)->toBe($account->id)
        ->and((float) $vcItem->amount)->toBe(100.0)
        ->and((float) $vc->subtotal_amount)->toBe(100.0)
        ->and((float) $vc->tax_amount)->toBe(10.0)
        ->and((float) $vc->total_amount)->toBe(110.0)
        ->and((float) $vc->amount_remaining)->toBe(110.0)
        ->and($vc->status)->toBe('open')
        ->and($vc->isFullyApplied())->toBeFalse();

    // Exceeding balance guard
    expect(fn () => $vc->applyToBill($bill->bill_id, 500.00))->toThrow(\Exception::class, 'exceeds remaining');

    // Partial then full application
    $vc->applyToBill($bill->bill_id, 50.00);
    expect($vc->fresh()->status)->toBe('partial');

    $app = $vc->applications()->first();
    expect($app)->toBeInstanceOf(VendorCreditApplication::class)
        ->and($app->vendorCredit->vendor_credit_id)->toBe($vc->vendor_credit_id)
        ->and($app->bill->bill_id)->toBe($bill->bill_id);

    $vc->applyToBill($bill->bill_id, 60.00);
    expect($vc->fresh()->status)->toBe('applied')
        ->and($vc->fresh()->isFullyApplied())->toBeTrue();

    // Second VendorCredit triggers generateCreditNumber increment branch + void()
    $vc2 = VendorCredit::create([
        'vendor_id' => $vendor->vendor_id,
        'credit_date' => now()->toDateString(),
        'total_amount' => 50.00,
        'team_id' => $team->id,
    ]);
    expect($vc2->vendor_credit_number)->not->toBe($vc->vendor_credit_number);
    $vc2->void();
    expect($vc2->status)->toBe('void');
});
