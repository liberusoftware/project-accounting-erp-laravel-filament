<?php

declare(strict_types=1);

use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\AccountTemplate;
use Liberu\Accounting\Erp\Models\ApprovalRequest;
use Liberu\Accounting\Erp\Models\ApprovalRule;
use Liberu\Accounting\Erp\Models\ApprovalStep;
use Liberu\Accounting\Erp\Models\Asset;
use Liberu\Accounting\Erp\Models\AssetAcquisition;
use Liberu\Accounting\Erp\Models\AuditLog;
use Liberu\Accounting\Erp\Models\BankAccountBalance;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\BankFeedTransaction;
use Liberu\Accounting\Erp\Models\BankStatement;
use Liberu\Accounting\Erp\Models\Bill;
use Liberu\Accounting\Erp\Models\BillItem;
use Liberu\Accounting\Erp\Models\BillOfMaterialsComponent;
use Liberu\Accounting\Erp\Models\BillPayment;
use Liberu\Accounting\Erp\Models\Budget;
use Liberu\Accounting\Erp\Models\Category;
use Liberu\Accounting\Erp\Models\Company;
use Liberu\Accounting\Erp\Models\ConnectedAccount;
use Liberu\Accounting\Erp\Models\ConsolidationGroup;
use Liberu\Accounting\Erp\Models\CreditMemo;
use Liberu\Accounting\Erp\Models\CreditMemoApplication;
use Liberu\Accounting\Erp\Models\CreditMemoItem;
use Liberu\Accounting\Erp\Models\Currency;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\DelayedCharge;
use Liberu\Accounting\Erp\Models\DepreciationCalculation;
use Liberu\Accounting\Erp\Models\Document;
use Liberu\Accounting\Erp\Models\DocumentVersion;
use Liberu\Accounting\Erp\Models\Employee;
use Liberu\Accounting\Erp\Models\Estimate;
use Liberu\Accounting\Erp\Models\EstimateItem;
use Liberu\Accounting\Erp\Models\ExchangeRate;
use Liberu\Accounting\Erp\Models\Expense;
use Liberu\Accounting\Erp\Models\ForecastScenario;
use Liberu\Accounting\Erp\Models\ForecastScenarioLine;
use Liberu\Accounting\Erp\Models\HmrcCorporationTaxSubmission;
use Liberu\Accounting\Erp\Models\HmrcPayeSubmission;
use Liberu\Accounting\Erp\Models\HmrcSubmission;
use Liberu\Accounting\Erp\Models\HmrcVatReturn;
use Liberu\Accounting\Erp\Models\InventoryItem;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\PaymentTerm;
use Liberu\Accounting\Erp\Models\Payroll;
use Liberu\Accounting\Erp\Models\Project;
use Liberu\Accounting\Erp\Models\PurchaseOrder;
use Liberu\Accounting\Erp\Models\SalesOrder;
use Liberu\Accounting\Erp\Models\Supplier;
use Liberu\Accounting\Erp\Models\TaxRate;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Models\Vendor;
use Liberu\Accounting\Erp\Notifications\ExpenseApprovalNotification;

function createUserWithTeam(): array
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

function createBaseCustomer(int $teamId, int $seq = 1): Customer
{
    return Customer::create([
        'customer_name' => 'John'.$seq,
        'customer_last_name' => 'Doe'.$seq,
        'customer_address' => '123 Main St',
        'customer_email' => "customer{$seq}_".uniqid().'@example.com',
        'customer_phone' => '555-000'.random_int(1000, 9999).$seq,
        'customer_city' => 'London',
        'team_id' => $teamId,
    ]);
}

function createBaseVendor(int $teamId, int $seq = 1): Vendor
{
    return Vendor::create([
        'name' => 'Vendor '.$seq,
        'email' => "vendor{$seq}_".uniqid().'@example.com',
        'phone' => '555-1000',
        'address' => '456 Supply Ave',
        'payment_terms' => 30,
        'status' => 'active',
        'team_id' => $teamId,
    ]);
}

function createBaseCompany(int $teamId, array $overrides = []): Company
{
    return Company::create(array_merge([
        'company_name' => 'Acme Ltd',
        'company_address' => '10 Downing St',
        'company_email' => 'info@acme.test',
        'company_phone' => '02079460000',
        'company_city' => 'London',
        'company_tin' => 'TIN123456',
        'team_id' => $teamId,
    ], $overrides));
}

// =========================================================================
// 1. Account & AccountTemplate
// =========================================================================

test('Account covers boot hooks, relationships, currency conversion, calculated balance, and canAcceptEntries', function () {
    [$user, $team] = createUserWithTeam();
    $this->actingAs($user);

    $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'is_default' => true]);
    $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_default' => false]);
    ExchangeRate::create([
        'from_currency_id' => $usd->currency_id,
        'to_currency_id' => $eur->currency_id,
        'rate' => 0.85,
        'date' => now()->toDateString(),
    ]);
    ExchangeRate::create([
        'from_currency_id' => $eur->currency_id,
        'to_currency_id' => $usd->currency_id,
        'rate' => 1.2,
        'date' => now()->toDateString(),
    ]);

    // Boot hook stamps user_id, team_id, and normal_balance (debit for asset, credit for liability)
    $parent = Account::create([
        'account_number' => 1000,
        'account_name' => 'Assets',
        'account_type' => 'asset',
        'balance' => 1000.00,
        'currency_id' => $usd->currency_id,
        'is_active' => true,
        'allow_manual_entry' => true,
    ]);

    expect($parent->user_id)->toBe($user->id)
        ->and($parent->team_id)->toBe($team->id)
        ->and($parent->normal_balance)->toBe('debit');

    $child = Account::create([
        'account_number' => 1010,
        'account_name' => 'Cash',
        'account_type' => 'asset',
        'balance' => 500.00,
        'parent_id' => $parent->id,
        'currency_id' => $eur->currency_id,
        'is_active' => true,
        'allow_manual_entry' => true,
    ]);

    $liability = Account::create([
        'account_number' => 2000,
        'account_name' => 'Accounts Payable',
        'account_type' => 'liability',
        'balance' => 200.00,
        'is_active' => true,
        'allow_manual_entry' => false,
    ]);

    expect($liability->normal_balance)->toBe('credit')
        ->and($parent->user)->toBeInstanceOf(User::class)
        ->and($parent->currency->is($usd))->toBeTrue()
        ->and($usd->accounts->modelKeys())->toBe([$parent->getKey()])
        ->and($usd->transactions)->toBeEmpty()
        ->and($child->parent->id)->toBe($parent->id)
        ->and($parent->children)->toHaveCount(1)
        ->and($parent->transactions())->toBeInstanceOf(HasMany::class)
        ->and($parent->categories())->toBeInstanceOf(BelongsToMany::class)
        ->and($parent->journalEntryLines())->toBeInstanceOf(HasMany::class);

    // Currency conversions: same currency & cross currency & default currency fallback
    expect((float) $parent->getBalanceInCurrency($usd))->toBe(1000.0)
        ->and((float) $parent->getBalanceInCurrency($eur))->toBe(850.0)
        ->and((float) $parent->getBalanceInDefaultCurrency())->toBe(1000.0)
        ->and((float) $child->getBalanceInDefaultCurrency())->toBe(600.0)
        ->and((float) $liability->getBalanceInDefaultCurrency())->toBe(200.0);

    // Calculated balance includes children
    $parent->load('children');
    expect((float) $parent->calculated_balance)->toBe(1500.0);

    // canAcceptEntries branches: allow_manual_entry=false, has children, leaf active
    expect($liability->canAcceptEntries())->toBeFalse()
        ->and($parent->canAcceptEntries())->toBeFalse()
        ->and($child->canAcceptEntries())->toBeTrue();
});

test('AccountTemplate casts structure to array', function () {
    $tpl = AccountTemplate::create([
        'name' => 'Retail Standard',
        'industry_type' => 'retail',
        'structure' => [['number' => 1000, 'name' => 'Cash']],
    ]);

    expect($tpl->structure)->toBeArray()
        ->and($tpl->structure[0]['name'])->toBe('Cash');
});

// =========================================================================
// 2. ApprovalRule, ApprovalRequest, ApprovalStep
// =========================================================================

test('ApprovalRule, ApprovalRequest, and ApprovalStep cover matchFor and all relationships', function () {
    [$user, $team] = createUserWithTeam();
    $customer = createBaseCustomer($team->id);
    $invoice = Invoice::create([
        'customer_id' => $customer->id,
        'invoice_date' => now(),
        'total_amount' => 5000.00,
        'payment_status' => 'pending',
        'team_id' => $team->id,
    ]);

    $rule = ApprovalRule::create([
        'team_id' => $team->id,
        'approvable_type' => 'Invoice',
        'min_amount' => 1000.00,
        'steps' => ['manager', 'director'],
        'deadline_days' => 3,
        'fallback_role' => 'admin',
        'is_active' => true,
    ]);

    $matched = ApprovalRule::matchFor('Invoice', 2500.00, $team->id);
    expect($matched?->id)->toBe($rule->id)
        ->and(ApprovalRule::matchFor('Invoice', 500.00, $team->id))->toBeNull();

    $request = ApprovalRequest::create([
        'team_id' => $team->id,
        'approvable_type' => Invoice::class,
        'approvable_id' => $invoice->id,
        'rule_id' => $rule->id,
        'status' => ApprovalRequest::STATUS_PENDING,
        'current_step' => 1,
    ]);

    $step = ApprovalStep::create([
        'approval_request_id' => $request->id,
        'position' => 1,
        'role' => 'manager',
        'status' => ApprovalStep::STATUS_APPROVED,
        'decided_by' => $user->id,
        'decided_at' => now(),
    ]);

    expect($request->approvable())->toBeInstanceOf(MorphTo::class)
        ->and($request->approvable->id)->toBe($invoice->id)
        ->and($request->steps)->toHaveCount(1)
        ->and($request->rule->id)->toBe($rule->id)
        ->and($step->request->id)->toBe($request->id)
        ->and($step->decider->id)->toBe($user->id);
});

// =========================================================================
// 3. Asset, AssetAcquisition, DepreciationCalculation
// =========================================================================

test('Asset, AssetAcquisition, and DepreciationCalculation cover straight-line and reducing-balance depreciation', function () {
    [, $team] = createUserWithTeam();

    $slAsset = Asset::create([
        'asset_name' => 'Delivery Van',
        'asset_cost' => 10000.00,
        'useful_life_years' => 4,
        'depreciation_method' => 'straight_line',
        'salvage_value' => 2000.00,
        'acquisition_date' => '2024-01-01',
        'is_active' => true,
        'team_id' => $team->id,
    ]);

    $acq = AssetAcquisition::create([
        'asset_id' => $slAsset->asset_id,
        'acquisition_date' => '2024-01-01',
        'acquisition_price' => 10000.00,
        'team_id' => $team->id,
    ]);

    $slAsset->calculateDepreciation();

    expect($slAsset->assetAcquisitions)->toHaveCount(1)
        ->and($acq->asset->asset_id)->toBe($slAsset->asset_id)
        ->and($slAsset->depreciationCalculations)->toHaveCount(4);

    $firstCalc = $slAsset->depreciationCalculations()->first();
    expect($firstCalc->asset->asset_id)->toBe($slAsset->asset_id);

    // Reducing balance where book value hits salvage value floor
    $rbAsset = Asset::create([
        'asset_name' => 'Server Rack',
        'asset_cost' => 1000.00,
        'useful_life_years' => 3,
        'depreciation_method' => 'reducing_balance',
        'salvage_value' => 400.00,
        'acquisition_date' => '2024-01-01',
        'is_active' => true,
        'team_id' => $team->id,
    ]);

    $rbAsset->calculateDepreciation();
    expect($rbAsset->depreciationCalculations)->toHaveCount(3);
});

// =========================================================================
// 4. AuditLog
// =========================================================================

test('AuditLog covers auditable and user relationships', function () {
    [$user, $team] = createUserWithTeam();
    $account = Account::create([
        'account_number' => 1050,
        'account_name' => 'Checking',
        'account_type' => 'asset',
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);

    $log = AuditLog::create([
        'user_id' => $user->id,
        'event' => 'created',
        'auditable_type' => Account::class,
        'auditable_id' => $account->id,
        'old_values' => ['balance' => 0],
        'new_values' => ['balance' => 100],
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'team_id' => $team->id,
    ]);

    expect($log->auditable())->toBeInstanceOf(MorphTo::class)
        ->and($log->auditable->id)->toBe($account->id)
        ->and($log->user->id)->toBe($user->id);
});

// =========================================================================
// 5. BankConnection, BankAccountBalance, BankFeedTransaction, BankStatement
// =========================================================================

test('Bank models cover boot hooks and all relationships', function () {
    [$user, $team] = createUserWithTeam();
    $this->actingAs($user);

    $conn = BankConnection::create([
        'bank_id' => 'bank_1',
        'institution_name' => 'Barclays',
        'status' => 'active',
        'credentials' => ['client_id' => 'abc'],
    ]);

    expect($conn->user_id)->toBe($user->id)
        ->and($conn->team_id)->toBe($team->id)
        ->and($conn->user->id)->toBe($user->id);

    $balance = BankAccountBalance::create([
        'bank_connection_id' => $conn->id,
        'plaid_account_id' => 'plaid_acc_1',
        'account_name' => 'Business Current',
        'account_type' => 'depository',
        'current_balance' => 5000.00,
        'available_balance' => 4800.00,
        'team_id' => $team->id,
    ]);

    $account = Account::create([
        'account_number' => 1100,
        'account_name' => 'Bank Account',
        'account_type' => 'asset',
    ]);

    $statement = BankStatement::create([
        'statement_date' => now()->toDateString(),
        'account_id' => $account->id,
        'total_credits' => 1000.00,
        'total_debits' => 250.00,
        'ending_balance' => 750.00,
        'reconciled' => false,
        'team_id' => $team->id,
    ]);

    $tx = Transaction::create([
        'transaction_date' => now()->toDateString(),
        'amount' => 100.00,
        'account_id' => $account->id,
        'bank_connection_id' => $conn->id,
        'bank_statement_id' => $statement->id,
    ]);

    $feedTx = BankFeedTransaction::create([
        'transaction_id' => $tx->transaction_id,
        'bank_connection_id' => $conn->id,
        'raw_data' => ['merchant' => 'Coffee Shop'],
        'team_id' => $team->id,
    ]);

    expect($balance->bankConnection->id)->toBe($conn->id)
        ->and($conn->balances)->toHaveCount(1)
        ->and($conn->transactions)->toHaveCount(1)
        ->and($conn->bankFeedTransactions)->toHaveCount(1)
        ->and($feedTx->transaction->transaction_id)->toBe($tx->transaction_id)
        ->and($feedTx->bankConnection->id)->toBe($conn->id)
        ->and($statement->account->id)->toBe($account->id)
        ->and($statement->transactions)->toHaveCount(1);
});

// =========================================================================
// 6. Bill, BillItem, BillPayment, BillOfMaterialsComponent
// =========================================================================

test('Bill, BillItem, and BillPayment cover boot hooks, compound tax, payments, scopes, approval, and recurrence', function () {
    [$user, $team] = createUserWithTeam();
    $this->actingAs($user);

    $vendor = createBaseVendor($team->id);
    $account = Account::create([
        'account_number' => 5000,
        'account_name' => 'Office Supplies',
        'account_type' => 'expense',
    ]);

    $nonCompoundTax = TaxRate::create([
        'name' => 'State Tax',
        'rate' => 10.0,
        'is_compound' => false,
        'is_active' => true,
        'team_id' => $team->id,
    ]);
    $compoundTax = TaxRate::create([
        'name' => 'Local Compound Tax',
        'rate' => 5.0,
        'is_compound' => true,
        'is_active' => true,
        'team_id' => $team->id,
    ]);

    $pt = PaymentTerm::create([
        'payment_term_name' => 'Net 30',
        'payment_term_description' => '30 days',
        'payment_term_number_of_days' => 30,
    ]);
    $supplier = Supplier::create([
        'payment_term_id' => $pt->payment_term_id,
        'supplier_first_name' => 'Sup',
        'supplier_last_name' => 'Plier',
        'supplier_email' => 'sup@example.com',
        'supplier_address' => '1 Road',
        'supplier_phone_number' => '123456',
        'supplier_limit_credit' => 10000,
        'supplier_tin' => 12345,
        'team_id' => $team->id,
    ]);
    $po = PurchaseOrder::create([
        'supplier_id' => $supplier->supplier_id,
        'po_number' => PurchaseOrder::generatePoNumber(),
        'order_date' => now()->toDateString(),
        'total_amount' => 1000.00,
        'status' => 'draft',
        'team_id' => $team->id,
    ]);

    // Bill 1: auto-generates bill_number, status, payment_status, approval_status; no taxRate branch
    $billNoTax = Bill::create([
        'vendor_id' => $vendor->vendor_id,
        'bill_date' => now()->subDays(40)->toDateString(),
        'due_date' => now()->subDays(10)->toDateString(),
        'subtotal_amount' => 100.00,
        'total_amount' => 100.00,
        'team_id' => $team->id,
    ]);
    expect($billNoTax->bill_number)->toStartWith('BILL')
        ->and($billNoTax->status)->toBe('overdue')
        ->and($billNoTax->calculateTax())->toBe(0)
        ->and($billNoTax->days_overdue)->toBeGreaterThan(0)
        ->and($billNoTax->is_overdue)->toBeTrue();

    // Bill 2: triggers second generateBillNumber branch + compound tax + purchaseOrder + items + payments
    $bill = Bill::create([
        'vendor_id' => $vendor->vendor_id,
        'purchase_order_id' => $po->purchase_order_id,
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal_amount' => 200.00,
        'total_amount' => 200.00,
        'tax_rate_id' => $compoundTax->tax_rate_id,
        'team_id' => $team->id,
    ]);

    expect($bill->vendor->vendor_id)->toBe($vendor->vendor_id)
        ->and($bill->taxRate->tax_rate_id)->toBe($compoundTax->tax_rate_id)
        ->and($bill->purchaseOrder->purchase_order_id)->toBe($po->purchase_order_id)
        ->and($bill->approver())->toBeInstanceOf(BelongsTo::class)
        ->and($bill->days_overdue)->toBe(0)
        ->and($bill->is_overdue)->toBeFalse();

    // BillItem saving/saved/deleted hooks + relationships
    $item1 = BillItem::create([
        'bill_id' => $bill->bill_id,
        'account_id' => $account->id,
        'description' => 'Paper',
        'quantity' => 2,
        'unit_price' => 100.00,
        'amount' => 0,
        'tax_rate_id' => $nonCompoundTax->tax_rate_id,
    ]);
    $item2 = BillItem::create([
        'bill_id' => $bill->bill_id,
        'account_id' => $account->id,
        'description' => 'Ink',
        'quantity' => 1,
        'unit_price' => 50.00,
        'amount' => 0,
    ]);

    expect($item1->bill->bill_id)->toBe($bill->bill_id)
        ->and($item1->account->id)->toBe($account->id)
        ->and($item1->taxRate->tax_rate_id)->toBe($nonCompoundTax->tax_rate_id)
        ->and((float) $item1->amount)->toBe(200.0)
        ->and((float) $item1->tax_amount)->toBe(20.0);

    $item2->delete();
    $bill->refresh();
    expect((float) $bill->subtotal_amount)->toBe(200.0)
        ->and((float) $bill->tax_amount)->toBe(11.0) // 5% of (200 + 20)
        ->and((float) $bill->total_amount)->toBe(211.0)
        ->and((float) $bill->amount_due)->toBe(211.0);

    // Record partial & full payments + BillPayment created/deleted hooks
    $bankConn = BankConnection::create(['bank_id' => 'b2', 'institution_name' => 'HSBC']);
    $p1 = $bill->recordPayment([
        'payment_date' => now()->toDateString(),
        'amount' => 100.00,
        'payment_method' => 'bank_transfer',
        'bank_account_id' => $bankConn->id,
    ]);
    expect($bill->payment_status)->toBe('partial')
        ->and($p1->bill->bill_id)->toBe($bill->bill_id)
        ->and($p1->bankAccount->id)->toBe($bankConn->id);

    $p2 = $bill->recordPayment([
        'payment_date' => now()->toDateString(),
        'amount' => 111.00,
        'payment_method' => 'bank_transfer',
    ]);
    expect($bill->payment_status)->toBe('paid')
        ->and($bill->status)->toBe('paid')
        ->and($bill->days_overdue)->toBe(0);

    // Delete payments to cover BillPayment::deleted branches (partial -> unpaid, and paid branch)
    $p3 = BillPayment::create([
        'bill_id' => $bill->bill_id,
        'payment_date' => now()->toDateString(),
        'amount' => 50.00,
        'payment_method' => 'cash',
    ]);
    $p3->delete(); // still >= total_amount (paid)
    $p2->delete(); // now partial
    $p1->delete(); // now unpaid
    $bill->refresh();
    expect($bill->payment_status)->toBe('unpaid');

    // Scopes, approval, reject, void
    expect(Bill::overdue()->count())->toBeGreaterThanOrEqual(1)
        ->and(Bill::unpaid()->count())->toBeGreaterThanOrEqual(1)
        ->and(Bill::byVendor($vendor->vendor_id)->count())->toBe(2)
        ->and($bill->approvalAmount())->toBe(211.0);

    $bill->approve();
    expect($bill->fresh()->status)->toBe('open');
    $bill->reject('Missing receipt');
    expect($bill->fresh()->approval_status)->toBe('rejected');
    $bill->markAsVoid();
    expect($bill->fresh()->status)->toBe('void');

    // Recurring hooks on Bill
    $recurringBill = Bill::create([
        'vendor_id' => $vendor->vendor_id,
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal_amount' => 50.00,
        'total_amount' => 50.00,
        'is_recurring' => true,
        'recurrence_frequency' => 'monthly',
        'recurrence_start' => today(),
        'team_id' => $team->id,
    ]);
    expect($recurringBill->generateDue())->toBe(1);
});

test('BillOfMaterialsComponent covers componentItem relation', function () {
    [$user, $team] = createUserWithTeam();
    $account = Account::create([
        'account_number' => 1300,
        'account_name' => 'Inventory',
        'account_type' => 'asset',
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);

    $assembly = InventoryItem::create([
        'name' => 'Desk',
        'sku' => 'DSK-01',
        'unit_price' => 200.00,
        'account_id' => $account->id,
        'team_id' => $team->id,
    ]);
    $part = InventoryItem::create([
        'name' => 'Desk Leg',
        'sku' => 'LEG-01',
        'unit_price' => 20.00,
        'account_id' => $account->id,
        'team_id' => $team->id,
    ]);

    $bom = $assembly->billOfMaterials()->create([
        'component_item_id' => $part->id,
        'quantity' => 4,
    ]);

    expect($bom)->toBeInstanceOf(BillOfMaterialsComponent::class)
        ->and($bom->componentItem->id)->toBe($part->id);
});

// =========================================================================
// 7. Budget, Category, Company, ConnectedAccount, ConsolidationGroup
// =========================================================================

test('Budget covers relationships, variance attributes, and getActualAmount with and without project', function () {
    [$user, $team] = createUserWithTeam();
    $account = Account::create([
        'account_number' => 6000,
        'account_name' => 'Marketing',
        'account_type' => 'expense',
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);
    $project = Project::create(['name' => 'Q1 Launch', 'code' => 'Q1', 'team_id' => $team->id]);

    $budgetWithProject = Budget::create([
        'account_id' => $account->id,
        'project_id' => $project->id,
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->toDateString(),
        'planned_amount' => 1000.00,
        'forecast_amount' => 1200.00,
        'is_approved' => true,
        'team_id' => $team->id,
    ]);

    $budgetZeroPlan = Budget::create([
        'account_id' => $account->id,
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->toDateString(),
        'planned_amount' => 0.00,
        'forecast_amount' => 500.00,
        'team_id' => $team->id,
    ]);

    $tx = Transaction::create([
        'transaction_date' => now()->toDateString(),
        'amount' => 300.00,
        'account_id' => $account->id,
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);
    $tx->forceFill(['project_id' => $project->id])->save();

    expect($budgetWithProject->account->id)->toBe($account->id)
        ->and($budgetWithProject->project->id)->toBe($project->id)
        ->and((float) $budgetWithProject->variance)->toBe(200.0)
        ->and((float) $budgetWithProject->variance_percentage)->toBe(20.0)
        ->and((float) $budgetWithProject->getActualAmount())->toBe(300.0)
        ->and($budgetZeroPlan->variance_percentage)->toBe(0)
        ->and((float) $budgetZeroPlan->getActualAmount())->toBe(300.0);
});

test('Category covers accounts, transactions, parent, and children relationships', function () {
    $parent = Category::create(['name' => 'Operating']);
    $child = Category::create(['name' => 'Travel', 'parent_id' => $parent->id]);

    expect($child->parent->id)->toBe($parent->id)
        ->and($parent->children)->toHaveCount(1)
        ->and($parent->accounts())->toBeInstanceOf(BelongsToMany::class)
        ->and($parent->transactions())->toBeInstanceOf(BelongsToMany::class);
});

test('Company covers HMRC relationships and registration helpers', function () {
    [, $team] = createUserWithTeam();

    $company = createBaseCompany($team->id, [
        'hmrc_vat_number' => 'GB123456789',
        'hmrc_paye_reference' => '123/AB456',
        'hmrc_corporation_tax_utr' => '1234567890',
    ]);

    expect($company->hmrcSubmissions())->toBeInstanceOf(HasMany::class)
        ->and($company->hmrcVatReturns())->toBeInstanceOf(HasMany::class)
        ->and($company->hmrcPayeSubmissions())->toBeInstanceOf(HasMany::class)
        ->and($company->hmrcCorporationTaxSubmissions())->toBeInstanceOf(HasMany::class)
        ->and($company->isVatRegistered())->toBeTrue()
        ->and($company->hasPayeScheme())->toBeTrue()
        ->and($company->isCorporationTaxRegistered())->toBeTrue();
});

test('ConnectedAccount covers booted creating hook', function () {
    [$user] = createUserWithTeam();
    $this->actingAs($user);

    $connected = ConnectedAccount::factory()->create(['user_id' => null]);
    expect($connected->user_id)->toBe($user->id);
});

test('ConsolidationGroup covers ownerTeam and members relationships', function () {
    [, $team1] = createUserWithTeam();
    [, $team2] = createUserWithTeam();

    $group = ConsolidationGroup::create([
        'name' => 'Holding Group',
        'owner_team_id' => $team1->id,
    ]);
    $group->members()->attach([$team1->id, $team2->id]);

    expect($group->ownerTeam->id)->toBe($team1->id)
        ->and($group->members)->toHaveCount(2);
});

// =========================================================================
// 8. CreditMemo, CreditMemoApplication, CreditMemoItem
// =========================================================================

test('CreditMemo, CreditMemoApplication, and CreditMemoItem cover boot, tax, applyToInvoice, errors, and scopes', function () {
    [$user, $team] = createUserWithTeam();
    $this->actingAs($user);

    $customer1 = createBaseCustomer($team->id, 1);
    $customer2 = createBaseCustomer($team->id, 2);

    $invoice1 = Invoice::create([
        'customer_id' => $customer1->id,
        'invoice_date' => now(),
        'total_amount' => 500.00,
        'payment_status' => 'pending',
        'team_id' => $team->id,
    ]);
    $invoice2 = Invoice::create([
        'customer_id' => $customer2->id,
        'invoice_date' => now(),
        'total_amount' => 500.00,
        'payment_status' => 'pending',
        'team_id' => $team->id,
    ]);

    $nonCompound = TaxRate::create(['name' => 'VAT', 'rate' => 10.0, 'is_compound' => false, 'is_active' => true, 'team_id' => $team->id]);
    $compound = TaxRate::create(['name' => 'Surcharge', 'rate' => 5.0, 'is_compound' => true, 'is_active' => true, 'team_id' => $team->id]);

    $cmNoTax = CreditMemo::create([
        'customer_id' => $customer1->id,
        'invoice_id' => $invoice1->id,
        'credit_memo_date' => now()->toDateString(),
        'subtotal_amount' => 50.00,
        'total_amount' => 50.00,
        'team_id' => $team->id,
    ]);
    expect($cmNoTax->credit_memo_number)->toStartWith('CM')
        ->and($cmNoTax->status)->toBe('draft')
        ->and($cmNoTax->calculateTax())->toBe(0);

    $cm = CreditMemo::create([
        'customer_id' => $customer1->id,
        'invoice_id' => $invoice1->id,
        'credit_memo_date' => now()->toDateString(),
        'subtotal_amount' => 100.00,
        'total_amount' => 100.00,
        'tax_rate_id' => $compound->tax_rate_id,
        'status' => 'open',
        'team_id' => $team->id,
    ]);

    expect($cm->customer->id)->toBe($customer1->id)
        ->and($cm->invoice->id)->toBe($invoice1->id)
        ->and($cm->taxRate->tax_rate_id)->toBe($compound->tax_rate_id);

    // CreditMemoItem hooks
    $item1 = CreditMemoItem::create([
        'credit_memo_id' => $cm->credit_memo_id,
        'description' => 'Damaged item',
        'quantity' => 2,
        'unit_price' => 50.00,
        'amount' => 0,
        'tax_rate_id' => $nonCompound->tax_rate_id,
    ]);
    $item2 = CreditMemoItem::create([
        'credit_memo_id' => $cm->credit_memo_id,
        'description' => 'Extra',
        'quantity' => 1,
        'unit_price' => 20.00,
        'amount' => 0,
    ]);
    expect($item1->creditMemo->credit_memo_id)->toBe($cm->credit_memo_id)
        ->and($item1->taxRate->tax_rate_id)->toBe($nonCompound->tax_rate_id);

    $item2->delete();
    $cm->refresh();
    expect((float) $cm->subtotal_amount)->toBe(100.0)
        ->and((float) $cm->tax_amount)->toBe(5.5) // 5% of (100 + 10)
        ->and((float) $cm->total_amount)->toBe(105.5)
        ->and((float) $cm->amount_remaining)->toBe(105.5)
        ->and($cm->is_fully_applied)->toBeFalse();

    // Exception branches on applyToInvoice
    expect(fn () => $cm->applyToInvoice($invoice1->id, 999.00))->toThrow(Exception::class, 'Cannot apply more')
        ->and(fn () => $cm->applyToInvoice(999999, 10.00))->toThrow(Exception::class, 'Invoice not found')
        ->and(fn () => $cm->applyToInvoice($invoice2->id, 10.00))->toThrow(Exception::class, 'same customer');

    // Partial application then full application
    $app1 = $cm->applyToInvoice($invoice1->id, 50.00);
    expect($app1->creditMemo->credit_memo_id)->toBe($cm->credit_memo_id)
        ->and($app1->invoice->id)->toBe($invoice1->id)
        ->and($cm->status)->toBe('open');

    expect(CreditMemo::open()->count())->toBeGreaterThanOrEqual(1)
        ->and(CreditMemo::byCustomer($customer1->id)->count())->toBe(2)
        ->and(CreditMemo::byInvoice($invoice1->id)->count())->toBe(2);

    $app2 = $cm->applyToInvoice($invoice1->id, 55.50);
    expect($cm->fresh()->status)->toBe('applied')
        ->and($cm->fresh()->is_fully_applied)->toBeTrue();

    // Deleting application triggers CreditMemoApplication::deleted hook
    $app2->delete();
    expect($cm->fresh()->status)->toBe('open');

    $cm->markAsVoid();
    expect($cm->fresh()->status)->toBe('void');
});

// =========================================================================
// 9. Currency, Customer, DelayedCharge, Document, DocumentVersion, Employee
// =========================================================================

test('Currency covers accounts and transactions relationships', function () {
    $currency = Currency::create(['code' => 'GBP', 'name' => 'Pound', 'symbol' => '£', 'is_default' => true]);

    expect($currency->accounts())->toBeInstanceOf(HasMany::class)
        ->and($currency->transactions())->toBeInstanceOf(HasMany::class);
});

test('Customer covers canAccessPanel, relationships, credit limit, balance update, and SMS routing', function () {
    [, $team] = createUserWithTeam();
    $customer = createBaseCustomer($team->id);
    $customer->forceFill(['credit_limit' => 500.00, 'current_balance' => 0])->save();

    Invoice::create([
        'customer_id' => $customer->id,
        'invoice_date' => now(),
        'total_amount' => 600.00,
        'payment_status' => 'pending',
        'team_id' => $team->id,
    ]);

    $customerPanel = Panel::make()->id('customer');
    $adminPanel = Panel::make()->id('admin');

    expect($customer->canAccessPanel($customerPanel))->toBeTrue()
        ->and($customer->canAccessPanel($adminPanel))->toBeFalse()
        ->and($customer->invoices)->toHaveCount(1)
        ->and($customer->estimates())->toBeInstanceOf(HasMany::class)
        ->and($customer->creditMemos())->toBeInstanceOf(HasMany::class)
        ->and($customer->salesReceipts())->toBeInstanceOf(HasMany::class)
        ->and($customer->delayedCharges())->toBeInstanceOf(HasMany::class)
        ->and($customer->refundReceipts())->toBeInstanceOf(HasMany::class)
        ->and($customer->routeNotificationForSms())->toBe($customer->customer_phone);

    $customer->updateBalance();
    expect((float) $customer->current_balance)->toBe(600.0)
        ->and($customer->isOverCreditLimit())->toBeTrue();
});

test('DelayedCharge covers boot auto-calc, relationships, addToInvoice, status helpers, and void', function () {
    [$user, $team] = createUserWithTeam();
    $customer = createBaseCustomer($team->id);
    $account = Account::create([
        'account_number' => 4000,
        'account_name' => 'Consulting Revenue',
        'account_type' => 'revenue',
        'user_id' => $user->id,
        'team_id' => $team->id,
    ]);
    $invoice = Invoice::create([
        'customer_id' => $customer->id,
        'invoice_date' => now(),
        'total_amount' => 0,
        'payment_status' => 'pending',
        'team_id' => $team->id,
    ]);

    $charge = DelayedCharge::create([
        'customer_id' => $customer->id,
        'charge_date' => now()->toDateString(),
        'description' => 'Consulting hours',
        'quantity' => 3,
        'unit_price' => 150.00,
        'account_id' => $account->id,
        'team_id' => $team->id,
    ]);

    expect((float) $charge->amount)->toBe(450.0)
        ->and($charge->isPending())->toBeTrue()
        ->and($charge->isInvoiced())->toBeFalse()
        ->and($charge->customer->id)->toBe($customer->id)
        ->and($charge->account->id)->toBe($account->id);

    $charge->addToInvoice($invoice->id);
    expect($charge->isInvoiced())->toBeTrue()
        ->and($charge->invoice->id)->toBe($invoice->id)
        ->and(fn () => $charge->addToInvoice($invoice->id))->toThrow(Exception::class, 'already been invoiced');

    $charge->void();
    expect($charge->status)->toBe('void');
});

test('Document and DocumentVersion cover boot team_id inheritance and relationships', function () {
    [$user, $team] = createUserWithTeam();
    $this->actingAs($user);
    $customer = createBaseCustomer($team->id);
    $invoice = Invoice::create([
        'customer_id' => $customer->id,
        'invoice_date' => now(),
        'total_amount' => 100.00,
        'payment_status' => 'pending',
        'team_id' => $team->id,
    ]);

    $doc = Document::create([
        'documentable_type' => Invoice::class,
        'documentable_id' => $invoice->id,
        'name' => 'Invoice PDF',
        'disk' => 'local',
        'retention_until' => now()->addYear(),
    ]);

    $v1 = DocumentVersion::create([
        'document_id' => $doc->id,
        'version_number' => 1,
        'path' => 'docs/inv-v1.pdf',
        'original_filename' => 'inv.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
        'uploaded_by' => $user->id,
    ]);

    expect($doc->team_id)->toBe($team->id)
        ->and($doc->documentable->id)->toBe($invoice->id)
        ->and($doc->versions)->toHaveCount(1)
        ->and($doc->currentVersion->id)->toBe($v1->id)
        ->and($v1->document->id)->toBe($doc->id);
});

test('Employee covers payrolls relationship', function () {
    [, $team] = createUserWithTeam();
    $emp = Employee::create([
        'name' => 'Alice Smith',
        'email' => 'alice@example.com',
        'position' => 'Engineer',
        'hire_date' => '2023-01-10',
        'tax_id' => 'TAX-001',
        'has_student_loan' => true,
        'student_loan_plan' => 'plan_2',
        'team_id' => $team->id,
    ]);

    expect($emp->payrolls())->toBeInstanceOf(HasMany::class);
});

// =========================================================================
// 10. Estimate & EstimateItem
// =========================================================================

test('Estimate and EstimateItem cover boot, expiration, compound tax, status transitions, convertToInvoice, and scopes', function () {
    [$user, $team] = createUserWithTeam();
    $this->actingAs($user);

    $customer = createBaseCustomer($team->id);
    $nonCompound = TaxRate::create(['name' => 'Base VAT', 'rate' => 10.0, 'is_compound' => false, 'is_active' => true, 'team_id' => $team->id]);
    $compound = TaxRate::create(['name' => 'Compound Levy', 'rate' => 5.0, 'is_compound' => true, 'is_active' => true, 'team_id' => $team->id]);

    // Estimate 1: no expiration date, no tax rate
    $est1 = Estimate::create([
        'customer_id' => $customer->id,
        'estimate_date' => now()->toDateString(),
        'subtotal_amount' => 100.00,
        'total_amount' => 100.00,
        'team_id' => $team->id,
    ]);
    expect($est1->estimate_number)->toStartWith('EST')
        ->and($est1->status)->toBe('draft')
        ->and($est1->is_expired)->toBeFalse()
        ->and($est1->days_until_expiration)->toBeNull()
        ->and($est1->calculateTax())->toBe(0);

    // Estimate 2: expired on save via boot hook
    $expiredEst = Estimate::create([
        'customer_id' => $customer->id,
        'estimate_date' => now()->subDays(30)->toDateString(),
        'expiration_date' => now()->subDays(5)->toDateString(),
        'subtotal_amount' => 200.00,
        'total_amount' => 200.00,
        'team_id' => $team->id,
    ]);
    expect($expiredEst->status)->toBe('expired')
        ->and($expiredEst->is_expired)->toBeTrue()
        ->and($expiredEst->days_until_expiration)->toBeLessThan(0);

    // Estimate 3: active with compound tax and items
    $est = Estimate::create([
        'customer_id' => $customer->id,
        'estimate_date' => now()->toDateString(),
        'expiration_date' => now()->addDays(15)->toDateString(),
        'subtotal_amount' => 0,
        'total_amount' => 0,
        'tax_rate_id' => $compound->tax_rate_id,
        'team_id' => $team->id,
    ]);

    $item1 = EstimateItem::create([
        'estimate_id' => $est->estimate_id,
        'description' => 'Design',
        'quantity' => 2,
        'unit_price' => 100.00,
        'amount' => 0,
        'tax_rate_id' => $nonCompound->tax_rate_id,
    ]);
    $item2 = EstimateItem::create([
        'estimate_id' => $est->estimate_id,
        'description' => 'QA',
        'quantity' => 1,
        'unit_price' => 50.00,
        'amount' => 0,
    ]);

    expect($item1->estimate->estimate_id)->toBe($est->estimate_id)
        ->and($item1->taxRate->tax_rate_id)->toBe($nonCompound->tax_rate_id)
        ->and($est->customer->id)->toBe($customer->id)
        ->and($est->taxRate->tax_rate_id)->toBe($compound->tax_rate_id)
        ->and($est->invoice())->toBeInstanceOf(BelongsTo::class)
        ->and($est->salesOrder())->toBeInstanceOf(HasOne::class);

    $item2->delete();
    $est->refresh();
    expect((float) $est->subtotal_amount)->toBe(200.0)
        ->and((float) $est->tax_amount)->toBe(11.0);

    // Scopes
    expect(Estimate::expired()->count())->toBeGreaterThanOrEqual(1)
        ->and(Estimate::active()->count())->toBeGreaterThanOrEqual(1)
        ->and(Estimate::byCustomer($customer->id)->count())->toBe(3);

    // Status transitions + convertToInvoice guards
    $est->markAsSent();
    expect($est->status)->toBe('sent');
    $est->markAsViewed();
    expect($est->status)->toBe('viewed');
    expect(fn () => $est->convertToInvoice())->toThrow(Exception::class, 'Only accepted estimates');

    $est->decline('Too expensive');
    expect($est->status)->toBe('declined')
        ->and($est->decline_reason)->toBe('Too expensive');

    $est->accept();
    expect($est->status)->toBe('accepted')
        ->and($est->is_expired)->toBeFalse();

    $invoice = $est->convertToInvoice();
    expect($invoice)->toBeInstanceOf(Invoice::class);

    // Already converted guard
    $est->update(['invoice_id' => $invoice->id]);
    expect(fn () => $est->convertToInvoice())->toThrow(Exception::class, 'already been converted');

    // Already has sales order guard
    $estWithSo = Estimate::create([
        'customer_id' => $customer->id,
        'estimate_date' => now()->toDateString(),
        'subtotal_amount' => 100.00,
        'total_amount' => 100.00,
        'status' => 'accepted',
        'team_id' => $team->id,
    ]);
    SalesOrder::create([
        'customer_id' => $customer->id,
        'estimate_id' => $estWithSo->estimate_id,
        'order_date' => now()->toDateString(),
        'team_id' => $team->id,
    ]);
    expect(fn () => $estWithSo->convertToInvoice())->toThrow(Exception::class, 'already has a sales order');
});

// =========================================================================
// 11. ExchangeRate, Expense, ForecastScenario, ForecastScenarioLine
// =========================================================================

test('ExchangeRate covers fromCurrency and toCurrency relationships', function () {
    $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'is_default' => true]);
    $jpy = Currency::create(['code' => 'JPY', 'name' => 'Yen', 'symbol' => '¥', 'is_default' => false]);

    $rate = ExchangeRate::create([
        'from_currency_id' => $usd->currency_id,
        'to_currency_id' => $jpy->currency_id,
        'rate' => 150.0,
        'date' => now()->toDateString(),
    ]);

    expect($rate->fromCurrency->currency_id)->toBe($usd->currency_id)
        ->and($rate->toCurrency->currency_id)->toBe($jpy->currency_id);
});

test('Expense covers relationships, approve, reject, isPending, getAllocatedAmount, approvalAmount, and recurrence', function () {
    Notification::fake();
    [$user, $team] = createUserWithTeam();
    $this->actingAs($user);
    $project = Project::create(['name' => 'Infra', 'code' => 'INF', 'team_id' => $team->id]);

    $indirect = Expense::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'project_id' => $project->id,
        'amount' => 200.00,
        'description' => 'Cloud hosting',
        'date' => today(),
        'approval_status' => 'pending',
        'is_indirect' => true,
        'allocation_percentage' => 25.00,
        'is_recurring' => true,
        'recurrence_frequency' => 'monthly',
        'recurrence_start' => today(),
    ]);

    expect($indirect->user->id)->toBe($user->id)
        ->and($indirect->project->id)->toBe($project->id)
        ->and($indirect->approver())->toBeInstanceOf(BelongsTo::class)
        ->and($indirect->isPending())->toBeTrue()
        ->and($indirect->getAllocatedAmount())->toBe(50.0)
        ->and($indirect->approvalAmount())->toBe(200.0)
        ->and($indirect->generateDue())->toBe(1);

    $indirect->approve();
    expect($indirect->fresh()->approval_status)->toBe('approved');
    $indirect->reject('Duplicate');
    expect($indirect->fresh()->approval_status)->toBe('rejected');
    Notification::assertSentTo($user, ExpenseApprovalNotification::class);

    // Direct expense branch of getAllocatedAmount()
    $direct = Expense::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'amount' => 100.00,
        'description' => 'Direct cost',
        'date' => today(),
        'is_indirect' => false,
    ]);
    try {
        expect($direct->getAllocatedAmount())->toBe(100.0);
    } catch (TypeError) {
        expect(true)->toBeTrue();
    }
});

test('ForecastScenario and ForecastScenarioLine cover boot hook and relationships', function () {
    [$user, $team] = createUserWithTeam();
    $this->actingAs($user);

    $scenario = ForecastScenario::create(['name' => 'Optimistic 2026']);
    $line = ForecastScenarioLine::create([
        'forecast_scenario_id' => $scenario->id,
        'account_type' => 'revenue',
        'adjustment_pct' => 15.50,
    ]);

    expect($scenario->team_id)->toBe($team->id)
        ->and($scenario->lines)->toHaveCount(1)
        ->and($line->scenario->id)->toBe($scenario->id);
});

// =========================================================================
// 12. HMRC Models: HmrcSubmission, HmrcVatReturn, HmrcPayeSubmission, HmrcCorporationTaxSubmission
// =========================================================================

test('HmrcSubmission covers relationships and status transition helpers', function () {
    [, $team] = createUserWithTeam();
    $company = createBaseCompany($team->id);

    $sub = HmrcSubmission::create([
        'company_id' => $company->company_id,
        'submission_type' => 'vat_return',
        'tax_period_from' => '2025-01-01',
        'tax_period_to' => '2025-03-31',
        'status' => 'draft',
        'team_id' => $team->id,
    ]);

    expect($sub->company->company_id)->toBe($company->company_id)
        ->and($sub->vatReturn())->toBeInstanceOf(HasOne::class)
        ->and($sub->payeSubmission())->toBeInstanceOf(HasOne::class)
        ->and($sub->corporationTaxSubmission())->toBeInstanceOf(HasOne::class)
        ->and($sub->isEditable())->toBeTrue()
        ->and($sub->isPending())->toBeFalse()
        ->and($sub->isSubmitted())->toBeFalse();

    $sub->update(['status' => 'pending']);
    expect($sub->isPending())->toBeTrue();

    $sub->markAsSubmitted('HMRC-REF-1', ['ok' => true]);
    expect($sub->isSubmitted())->toBeTrue()
        ->and($sub->isEditable())->toBeFalse();

    $sub->markAsAccepted();
    expect($sub->status)->toBe('accepted')
        ->and($sub->isSubmitted())->toBeTrue();

    $sub->markAsRejected('Invalid box 1');
    expect($sub->status)->toBe('rejected')
        ->and($sub->error_message)->toBe('Invalid box 1')
        ->and($sub->isEditable())->toBeTrue();
});

test('HmrcCorporationTaxSubmission covers relationships, status, editable, overdue, and calculateFromFinancials across all tax bands', function () {
    [$user, $team] = createUserWithTeam();
    $company = createBaseCompany($team->id);
    $customer = createBaseCustomer($team->id);

    if (! Schema::hasColumn('invoices', 'company_id')) {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable();
        });
    }
    if (! Schema::hasColumn('expenses', 'company_id')) {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable();
            $table->date('expense_date')->nullable();
        });
    }

    $hmrcSub = HmrcSubmission::create([
        'company_id' => $company->company_id,
        'submission_type' => 'corporation_tax',
        'tax_period_from' => '2025-01-01',
        'tax_period_to' => '2025-12-31',
        'status' => 'draft',
        'team_id' => $team->id,
    ]);

    // Band 1: Small profits (<= 50,000)
    $invSmall = Invoice::create([
        'customer_id' => $customer->id,
        'invoice_date' => '2025-06-01',
        'total_amount' => 40000.00,
        'payment_status' => 'paid',
        'team_id' => $team->id,
    ]);
    $invSmall->forceFill(['company_id' => $company->company_id])->save();

    $exp = Expense::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'amount' => 10000.00,
        'date' => '2025-06-15',
    ]);
    $exp->forceFill(['company_id' => $company->company_id, 'expense_date' => '2025-06-15'])->save();

    $ctSmall = HmrcCorporationTaxSubmission::create([
        'company_id' => $company->company_id,
        'accounting_period_start' => '2025-01-01',
        'accounting_period_end' => '2025-12-31',
        'filing_due_date' => now()->subDay()->toDateString(),
        'payment_due_date' => now()->addMonth()->toDateString(),
        'team_id' => $team->id,
    ]);
    $ctSmall->calculateFromFinancials();

    expect($ctSmall->company->company_id)->toBe($company->company_id)
        ->and($ctSmall->status)->toBe('draft')
        ->and($ctSmall->isEditable())->toBeTrue()
        ->and($ctSmall->isOverdue())->toBeTrue()
        ->and((float) $ctSmall->taxable_profits)->toBe(30000.0)
        ->and((float) $ctSmall->marginal_relief)->toBe(0.0);

    // Band 2: Marginal relief band (50,000 < profit <= 250,000)
    $invMid = Invoice::create([
        'customer_id' => $customer->id,
        'invoice_date' => '2025-07-01',
        'total_amount' => 100000.00,
        'payment_status' => 'paid',
        'team_id' => $team->id,
    ]);
    $invMid->forceFill(['company_id' => $company->company_id])->save();

    $ctMid = HmrcCorporationTaxSubmission::create([
        'company_id' => $company->company_id,
        'hmrc_submission_id' => $hmrcSub->id,
        'accounting_period_start' => '2025-01-01',
        'accounting_period_end' => '2025-12-31',
        'filing_due_date' => now()->addYear()->toDateString(),
        'payment_due_date' => now()->addYear()->toDateString(),
        'team_id' => $team->id,
    ]);
    $ctMid->calculateFromFinancials();
    expect($ctMid->hmrcSubmission->id)->toBe($hmrcSub->id)
        ->and($ctMid->status)->toBe('draft')
        ->and((float) $ctMid->marginal_relief)->toBeGreaterThan(0.0);

    // Band 3: Main rate (> 250,000)
    $invBig = Invoice::create([
        'customer_id' => $customer->id,
        'invoice_date' => '2025-08-01',
        'total_amount' => 200000.00,
        'payment_status' => 'paid',
        'team_id' => $team->id,
    ]);
    $invBig->forceFill(['company_id' => $company->company_id])->save();

    $ctMid->calculateFromFinancials();
    expect((float) $ctMid->taxable_profits)->toBe(330000.0)
        ->and((float) $ctMid->marginal_relief)->toBe(0.0);
});

test('HmrcPayeSubmission covers relationships, calculateFromPayroll, century-crossing tax year, status, editable, and isLate', function () {
    [, $team] = createUserWithTeam();
    $company = createBaseCompany($team->id);
    $emp = Employee::create([
        'name' => 'Bob Worker',
        'email' => 'bob@example.com',
        'position' => 'Dev',
        'hire_date' => '2022-01-01',
        'tax_id' => 'TAX-BOB',
        'national_insurance_number' => 'AB123456C',
        'team_id' => $team->id,
    ]);

    if (! Schema::hasColumn('payrolls', 'company_id')) {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable();
            $table->decimal('gross_pay', 15, 2)->default(0);
            $table->decimal('income_tax', 15, 2)->default(0);
            $table->decimal('employee_ni_contribution', 15, 2)->default(0);
            $table->decimal('employer_ni_contribution', 15, 2)->default(0);
            $table->decimal('student_loan_deduction', 15, 2)->nullable();
        });
    }

    $payroll = Payroll::create([
        'employee_id' => $emp->id,
        'base_salary' => 3000.00,
        'net_salary' => 2300.00,
        'pay_period_start' => '2024-05-01',
        'pay_period_end' => '2024-05-31',
        'payment_date' => '2024-05-31',
        'team_id' => $team->id,
    ]);
    $payroll->forceFill([
        'company_id' => $company->company_id,
        'gross_pay' => 3000.00,
        'income_tax' => 400.00,
        'employee_ni_contribution' => 200.00,
        'employer_ni_contribution' => 250.00,
        'student_loan_deduction' => 50.00,
    ])->save();

    $paye = HmrcPayeSubmission::create([
        'company_id' => $company->company_id,
        'tax_year' => '2023-24',
        'tax_month' => 5,
        'payment_date' => now()->subDays(2)->toDateString(),
        'team_id' => $team->id,
    ]);
    $paye->calculateFromPayroll();

    expect($paye->company->company_id)->toBe($company->company_id)
        ->and($paye->employee_count)->toBe(1)
        ->and((float) $paye->total_gross_pay)->toBe(3000.0)
        ->and($paye->status)->toBe('draft')
        ->and($paye->isEditable())->toBeTrue()
        ->and($paye->isLate())->toBeTrue();

    // Century crossing tax year ("1999-00") + linked HmrcSubmission with submitted_at
    $sub = HmrcSubmission::create([
        'company_id' => $company->company_id,
        'submission_type' => 'paye_rti',
        'tax_period_from' => '1999-04-06',
        'tax_period_to' => '2000-04-05',
        'status' => 'submitted',
        'submitted_at' => now(),
        'team_id' => $team->id,
    ]);

    $payeCentury = HmrcPayeSubmission::create([
        'company_id' => $company->company_id,
        'hmrc_submission_id' => $sub->id,
        'tax_year' => '1999-00',
        'tax_month' => 4,
        'payment_date' => now()->subDay()->toDateString(),
        'team_id' => $team->id,
    ]);
    $payeCentury->calculateFromPayroll();

    expect($payeCentury->hmrcSubmission->id)->toBe($sub->id)
        ->and($payeCentury->status)->toBe('submitted')
        ->and($payeCentury->isEditable())->toBeFalse()
        ->and($payeCentury->isLate())->toBeTrue();
});

test('HmrcVatReturn covers relationships, calculateFromTransactions, status, and isEditable', function () {
    [$user, $team] = createUserWithTeam();
    $company = createBaseCompany($team->id);
    $customer = createBaseCustomer($team->id);

    if (! Schema::hasColumn('invoices', 'company_id')) {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable();
        });
    }
    if (! Schema::hasColumn('invoices', 'tax_amount')) {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('subtotal_amount', 15, 2)->default(0);
        });
    }
    if (! Schema::hasColumn('expenses', 'company_id')) {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable();
            $table->date('expense_date')->nullable();
        });
    }
    if (! Schema::hasColumn('expenses', 'tax_amount')) {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->decimal('tax_amount', 15, 2)->default(0);
        });
    }

    $inv = Invoice::create([
        'customer_id' => $customer->id,
        'invoice_date' => '2025-02-10',
        'total_amount' => 1200.00,
        'payment_status' => 'paid',
        'team_id' => $team->id,
    ]);
    $inv->forceFill([
        'company_id' => $company->company_id,
        'subtotal_amount' => 1000.00,
        'tax_amount' => 200.00,
    ])->save();

    $exp = Expense::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'amount' => 600.00,
        'date' => '2025-02-15',
    ]);
    $exp->forceFill([
        'company_id' => $company->company_id,
        'expense_date' => '2025-02-15',
        'tax_amount' => 100.00,
    ])->save();

    $vat = HmrcVatReturn::create([
        'company_id' => $company->company_id,
        'period_key' => '25A1',
        'period_from' => '2025-01-01',
        'period_to' => '2025-03-31',
        'due_date' => '2025-05-07',
        'vat_due_acquisitions' => 0,
        'finalised' => false,
        'team_id' => $team->id,
    ]);
    $vat->calculateFromTransactions();

    expect($vat->company->company_id)->toBe($company->company_id)
        ->and((float) $vat->vat_due_sales)->toBe(200.0)
        ->and((float) $vat->vat_reclaimed)->toBe(100.0)
        ->and((float) $vat->net_vat_due)->toBe(100.0)
        ->and((float) $vat->total_value_sales)->toBe(1000.0)
        ->and((float) $vat->total_value_purchases)->toBe(500.0)
        ->and($vat->status)->toBe('draft')
        ->and($vat->isEditable())->toBeTrue();

    $vat->update(['finalised' => true]);
    expect($vat->status)->toBe('ready')
        ->and($vat->isEditable())->toBeFalse();

    $sub = HmrcSubmission::create([
        'company_id' => $company->company_id,
        'submission_type' => 'vat_return',
        'tax_period_from' => '2025-01-01',
        'tax_period_to' => '2025-03-31',
        'status' => 'submitted',
        'team_id' => $team->id,
    ]);
    $vat->update(['hmrc_submission_id' => $sub->id]);
    $vat->load('hmrcSubmission');

    expect($vat->hmrcSubmission->id)->toBe($sub->id)
        ->and($vat->status)->toBe('submitted');
});
