<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\User as BaseAuthenticatable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Jetstream;
use Liberu\Accounting\Erp\Contracts\ApprovableRecord;
use Liberu\Accounting\Erp\Contracts\Documentable;
use Liberu\Accounting\Erp\Database\Seeders\AccountTemplateSeeder;
use Liberu\Accounting\Erp\Database\Seeders\DatabaseSeeder;
use Liberu\Accounting\Erp\Database\Seeders\MenuSeeder;
use Liberu\Accounting\Erp\Database\Seeders\PermissionsSeeder;
use Liberu\Accounting\Erp\Database\Seeders\RolesSeeder;
use Liberu\Accounting\Erp\Database\Seeders\TeamSeeder;
use Liberu\Accounting\Erp\Database\Seeders\UserSeeder;
use Liberu\Accounting\Erp\ErpServiceProvider;
use Liberu\Accounting\Erp\Events\ApprovableApproved;
use Liberu\Accounting\Erp\Events\ApprovableRejected;
use Liberu\Accounting\Erp\Exceptions\ApprovalDeniedException;
use Liberu\Accounting\Erp\Jobs\EscalateApprovalsJob;
use Liberu\Accounting\Erp\Jobs\SyncAccountingProviderConnectionJob;
use Liberu\Accounting\Erp\Jobs\SyncPlaidTransactionsJob;
use Liberu\Accounting\Erp\Listeners\CreatePersonalTeam;
use Liberu\Accounting\Erp\Listeners\SendApprovedOutboundPayment;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\AccountTemplate;
use Liberu\Accounting\Erp\Models\ApprovalRequest;
use Liberu\Accounting\Erp\Models\ApprovalRule;
use Liberu\Accounting\Erp\Models\ApprovalStep;
use Liberu\Accounting\Erp\Models\AuditLog;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\BankFeedTransaction;
use Liberu\Accounting\Erp\Models\BankStatement;
use Liberu\Accounting\Erp\Models\Bill;
use Liberu\Accounting\Erp\Models\Budget;
use Liberu\Accounting\Erp\Models\Company;
use Liberu\Accounting\Erp\Models\ConnectedAccount;
use Liberu\Accounting\Erp\Models\Currency;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\Estimate;
use Liberu\Accounting\Erp\Models\ExchangeRate;
use Liberu\Accounting\Erp\Models\Expense;
use Liberu\Accounting\Erp\Models\InventoryItem;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\InvoiceItem;
use Liberu\Accounting\Erp\Models\JournalEntry;
use Liberu\Accounting\Erp\Models\Menu;
use Liberu\Accounting\Erp\Models\OutboundPayment;
use Liberu\Accounting\Erp\Models\Project;
use Liberu\Accounting\Erp\Models\QboConnection;
use Liberu\Accounting\Erp\Models\ReconciliationRule;
use Liberu\Accounting\Erp\Models\SageConnection;
use Liberu\Accounting\Erp\Models\TaxRate;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Models\UserNotificationPreference;
use Liberu\Accounting\Erp\Models\Vendor;
use Liberu\Accounting\Erp\Models\XeroConnection;
use Liberu\Accounting\Erp\Notifications\ApprovalRequestedNotification;
use Liberu\Accounting\Erp\Notifications\Channels\SmsChannel;
use Liberu\Accounting\Erp\Notifications\CollectionNotification;
use Liberu\Accounting\Erp\Notifications\ExpenseApprovalNotification;
use Liberu\Accounting\Erp\Notifications\ImportFailedNotification;
use Liberu\Accounting\Erp\Notifications\PaymentReminderNotification;
use Liberu\Accounting\Erp\Notifications\PortalAccessNotification;
use Liberu\Accounting\Erp\Observers\InvoiceObserver;
use Liberu\Accounting\Erp\Policies\BillOfMaterialsPolicy;
use Liberu\Accounting\Erp\Policies\ConnectedAccountPolicy;
use Liberu\Accounting\Erp\Providers\EventServiceProvider;
use Liberu\Accounting\Erp\Rules\DoubleEntryValidator;
use Liberu\Accounting\Erp\Services\PlaidService;
use Liberu\Accounting\Erp\Services\QuickBooksService;
use Liberu\Accounting\Erp\Services\RevolutService;
use Liberu\Accounting\Erp\Services\SageService;
use Liberu\Accounting\Erp\Services\TeamManagementService;
use Liberu\Accounting\Erp\Services\XeroService;
use Liberu\Accounting\Erp\Settings\GeneralSettings;
use Liberu\Foundation\Settings\Settings\SiteSettings;
use Spatie\Permission\Models\Role;

function makeSupportUserWithTeam(?string $email = null): array
{
    $user = User::factory()->create($email ? ['email' => $email] : []);
    $team = Team::forceCreate([
        'user_id' => $user->id,
        'name' => $user->name."'s Team",
        'personal_team' => true,
    ]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $team->users()->syncWithoutDetaching([$user->id => ['role' => 'admin']]);

    return [$user->fresh(), $team->fresh()];
}

// =========================================================================
// Concerns, Contracts, Events, Exceptions
// =========================================================================

test('Approvable trait handles missing team, auto-approve, multi-step rules, idempotency, and rejection', function (): void {
    Event::fake([ApprovableApproved::class, ApprovableRejected::class]);
    Notification::fake();

    [$user, $team] = makeSupportUserWithTeam('approvable-owner@example.com');
    setPermissionsTeamId($team->id);
    Role::firstOrCreate(['name' => 'approver', 'guard_name' => 'web', 'team_id' => $team->id]);
    $user->assignRole('approver');
    $this->actingAs($user);

    // Contract checks
    $noTeamExpense = Expense::create([
        'user_id' => $user->id,
        'team_id' => null,
        'amount' => 100.00,
        'description' => 'No Team',
        'date' => today(),
    ]);
    expect($noTeamExpense)->toBeInstanceOf(ApprovableRecord::class);

    // Fail closed when team_id is missing
    expect(fn () => $noTeamExpense->submitForApproval())
        ->toThrow(RuntimeException::class, 'Cannot submit for approval: document has no team.');

    // Auto-approve when no ApprovalRule matches
    $smallExpense = Expense::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'amount' => 50.00,
        'description' => 'Coffee',
        'date' => today(),
    ]);
    $smallExpense->submitForApproval();

    expect($smallExpense->fresh()->approval_status)->toBe('approved')
        ->and($smallExpense->fresh()->approved_by)->toBe($user->id);
    Event::assertDispatched(ApprovableApproved::class);

    // Matching ApprovalRule creates request + steps + notifies role
    ApprovalRule::create([
        'team_id' => $team->id,
        'approvable_type' => 'Expense',
        'min_amount' => 500.00,
        'steps' => ['approver'],
        'deadline_days' => 2,
        'fallback_role' => 'approver',
        'is_active' => true,
    ]);

    $bigExpense = Expense::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'amount' => 1500.00,
        'description' => 'Laptop',
        'date' => today(),
    ]);
    $bigExpense->submitForApproval();

    expect($bigExpense->fresh()->approval_status)->toBe('pending')
        ->and($bigExpense->approvalRequest)->toBeInstanceOf(ApprovalRequest::class)
        ->and($bigExpense->approvalRequest->steps()->count())->toBe(1);
    Notification::assertSentTo($user, ApprovalRequestedNotification::class);

    // Idempotent second submit does not spawn a second chain
    $bigExpense->submitForApproval();
    expect(ApprovalRequest::where('approvable_id', $bigExpense->id)->count())->toBe(1);

    // Rejection
    $bigExpense->markRejected('Over budget');
    expect($bigExpense->fresh()->approval_status)->toBe('rejected')
        ->and($bigExpense->fresh()->rejection_reason)->toBe('Over budget');
    Event::assertDispatched(ApprovableRejected::class, fn (ApprovableRejected $e) => $e->reason === 'Over budget');

    // ApprovalDeniedException
    $denied = new ApprovalDeniedException('Forbidden');
    expect($denied)->toBeInstanceOf(RuntimeException::class)
        ->and($denied->getMessage())->toBe('Forbidden');
});

test('HasDocuments and Recurring traits cover relations, frequencies, cloning, and safety cap', function (): void {
    [$user, $team] = makeSupportUserWithTeam('recurring-owner@example.com');

    // HasDocuments & Documentable
    $invoice = Invoice::factory()->create(['team_id' => $team->id]);
    expect($invoice)->toBeInstanceOf(Documentable::class)
        ->and($invoice->documents()->get())->toHaveCount(0);

    // Non-recurring returns 0
    expect($invoice->generateDue())->toBe(0);

    // Invalid frequency returns 0
    $invoice->forceFill([
        'is_recurring' => true,
        'recurrence_start' => today(),
        'recurrence_frequency' => 'hourly',
    ])->save();
    expect($invoice->generateDue())->toBe(0);

    // Recurring Invoice with line items (daily, weekly, monthly, yearly + end date + catch-up)
    $account = Account::factory()->create(['user_id' => $user->id, 'team_id' => $team->id]);
    $template = Invoice::factory()->create([
        'team_id' => $team->id,
        'is_recurring' => true,
        'recurrence_frequency' => 'daily',
        'recurrence_start' => today()->subDays(2),
        'recurrence_end' => today()->subDay(),
        'last_generated' => null,
    ]);
    InvoiceItem::create([
        'invoice_id' => $template->id,
        'account_id' => $account->id,
        'description' => 'Retainer',
        'quantity' => 1,
        'unit_price' => 100.00,
        'amount' => 100.00,
    ]);

    expect($template->generateDue())->toBe(2)
        ->and($template->generateDue())->toBe(0);

    // Exercise weekly, monthly, yearly, and default branch of nextRecurringDate()
    $nextMethod = new ReflectionMethod($template, 'nextRecurringDate');
    $base = Carbon::parse('2026-01-01');

    $template->recurrence_frequency = 'weekly';
    expect($nextMethod->invoke($template, $base)->toDateString())->toBe('2026-01-08');

    $template->recurrence_frequency = 'monthly';
    expect($nextMethod->invoke($template, $base)->toDateString())->toBe('2026-02-01');

    $template->recurrence_frequency = 'yearly';
    expect($nextMethod->invoke($template, $base)->toDateString())->toBe('2027-01-01');

    $template->recurrence_frequency = 'unknown';
    expect($nextMethod->invoke($template, $base)->toDateString())->toBe('2026-01-01');

    // Exercise recurringSafetyCap break + log warning
    $expenseTemplate = Expense::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'amount' => 10.00,
        'description' => 'Daily cap test',
        'date' => today(),
        'is_recurring' => true,
        'recurrence_frequency' => 'daily',
        'recurrence_start' => today()->subDays(5),
        'last_generated' => null,
    ]);
    $capProp = new ReflectionProperty($expenseTemplate, 'recurringSafetyCap');
    $capProp->setValue($expenseTemplate, 2);

    expect($expenseTemplate->generateDue())->toBe(2);
});

// =========================================================================
// Jobs
// =========================================================================

test('EscalateApprovalsJob escalates overdue steps and notifies primary and fallback roles', function (): void {
    Notification::fake();

    [$user, $team] = makeSupportUserWithTeam('escalate-owner@example.com');
    setPermissionsTeamId($team->id);
    Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web', 'team_id' => $team->id]);
    Role::firstOrCreate(['name' => 'director', 'guard_name' => 'web', 'team_id' => $team->id]);
    $user->assignRole(['manager', 'director']);

    $rule = ApprovalRule::create([
        'team_id' => $team->id,
        'approvable_type' => 'Expense',
        'min_amount' => 100,
        'steps' => ['manager'],
        'deadline_days' => 1,
        'fallback_role' => 'director',
        'is_active' => true,
    ]);

    $request = ApprovalRequest::create([
        'team_id' => $team->id,
        'approvable_type' => Expense::class,
        'approvable_id' => 1,
        'rule_id' => $rule->id,
        'status' => ApprovalRequest::STATUS_PENDING,
        'current_step' => 1,
    ]);

    $step = ApprovalStep::create([
        'approval_request_id' => $request->id,
        'position' => 1,
        'role' => 'manager',
        'status' => ApprovalStep::STATUS_PENDING,
        'deadline_at' => now()->subHour(),
    ]);

    (new EscalateApprovalsJob())->handle();

    expect($step->fresh()->status)->toBe(ApprovalStep::STATUS_ESCALATED)
        ->and($step->fresh()->escalated_at)->not->toBeNull();
    Notification::assertSentToTimes($user, ApprovalRequestedNotification::class, 2);
});

test('SyncAccountingProviderConnectionJob syncs qbo, xero, sage, skips inactive, and rejects invalid provider', function (): void {
    [$user, $team] = makeSupportUserWithTeam('sync-provider@example.com');

    $qboConn = QboConnection::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'realm_id' => 'realm-1',
        'access_token' => 'tok',
        'refresh_token' => 'ref',
        'status' => 'active',
    ]);
    $xeroConn = XeroConnection::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'tenant_id' => 'tenant-1',
        'access_token' => 'tok',
        'refresh_token' => 'ref',
        'status' => 'active',
    ]);
    $sageConn = SageConnection::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'business_id' => 'biz-1',
        'access_token' => 'tok',
        'refresh_token' => 'ref',
        'status' => 'active',
    ]);
    $inactiveConn = QboConnection::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'realm_id' => 'realm-2',
        'access_token' => 'tok',
        'refresh_token' => 'ref',
        'status' => 'disconnected',
    ]);

    $qboService = Mockery::mock(QuickBooksService::class);
    $qboService->shouldReceive('sync')->once();
    $xeroService = Mockery::mock(XeroService::class);
    $xeroService->shouldReceive('sync')->once();
    $sageService = Mockery::mock(SageService::class);
    $sageService->shouldReceive('sync')->once();

    (new SyncAccountingProviderConnectionJob('qbo', $qboConn->id))->handle($qboService, $xeroService, $sageService);
    (new SyncAccountingProviderConnectionJob('xero', $xeroConn->id))->handle($qboService, $xeroService, $sageService);
    (new SyncAccountingProviderConnectionJob('sage', $sageConn->id))->handle($qboService, $xeroService, $sageService);
    (new SyncAccountingProviderConnectionJob('qbo', $inactiveConn->id))->handle($qboService, $xeroService, $sageService);

    expect(fn () => (new SyncAccountingProviderConnectionJob('unknown', 1))->handle($qboService, $xeroService, $sageService))
        ->toThrow(InvalidArgumentException::class);
});

test('SyncPlaidTransactionsJob handles inactive, added/modified/removed, reauth error, and failed hook', function (): void {
    // Inactive connection short-circuits
    $inactive = BankConnection::factory()->disconnected()->create();
    $plaidUnused = Mockery::mock(PlaidService::class);
    $plaidUnused->shouldNotReceive('syncTransactions');
    (new SyncPlaidTransactionsJob($inactive->id))->handle($plaidUnused);

    // Active connection with added, modified, removed
    $active = BankConnection::factory()->active()->create();
    $toRemove = Transaction::factory()->create([
        'external_id' => 'plaid-rem-1',
        'bank_connection_id' => $active->id,
    ]);

    $plaid = Mockery::mock(PlaidService::class);
    $plaid->shouldReceive('syncTransactions')->once()->andReturn([
        'added' => [
            [
                'transaction_id' => 'plaid-add-1',
                'date' => '2026-01-10',
                'amount' => 45.50,
                'name' => 'Coffee Shop',
                'category' => ['Food and Drink', 'Coffee'],
                'pending' => false,
            ],
            [
                'transaction_id' => 'plaid-add-2',
                'authorized_date' => '2026-01-11',
                'amount' => -200.00,
                'name' => 'Client Refund',
                'category' => [],
                'pending' => true,
            ],
        ],
        'modified' => [
            [
                'transaction_id' => 'plaid-add-1',
                'date' => '2026-01-10',
                'amount' => 46.00,
                'name' => 'Coffee Shop Updated',
                'category' => ['Food and Drink', 'Cafe'],
                'pending' => false,
            ],
        ],
        'removed' => [
            ['transaction_id' => 'plaid-rem-1'],
        ],
    ]);

    (new SyncPlaidTransactionsJob($active->id))->handle($plaid);

    expect(Transaction::where('external_id', 'plaid-add-1')->first()?->category)->toBe('cafe')
        ->and(Transaction::where('external_id', 'plaid-add-2')->first()?->category)->toBe('uncategorized')
        ->and(Transaction::where('external_id', 'plaid-add-2')->first()?->type)->toBe('credit')
        ->and(Transaction::where('external_id', 'plaid-rem-1')->exists())->toBeFalse()
        ->and(BankFeedTransaction::where('bank_connection_id', $active->id)->count())->toBe(2);

    // User action required error marks connection as requires_reauth
    $plaidReauth = Mockery::mock(PlaidService::class);
    $plaidReauth->shouldReceive('syncTransactions')->once()
        ->andThrow(new Exception('ITEM_LOGIN_REQUIRED: User action required'));

    $job = new SyncPlaidTransactionsJob($active->id);
    expect(fn () => $job->handle($plaidReauth))->toThrow(Exception::class);
    expect($active->fresh()->status)->toBe('requires_reauth');

    // failed() hook logs cleanly
    $job->failed(new Exception('Permanent failure'));
});

// =========================================================================
// Listeners
// =========================================================================

test('CreatePersonalTeam and SendApprovedOutboundPayment handle all paths', function (): void {
    [$user, $team] = makeSupportUserWithTeam('listener-owner@example.com');

    // CreatePersonalTeam
    $teamService = Mockery::mock(TeamManagementService::class);
    $teamService->shouldReceive('assignUserToDefaultTeam')->once()->with($user);
    (new CreatePersonalTeam($teamService))->handle(new Registered($user));

    // SendApprovedOutboundPayment: non-OutboundPayment is no-op
    $revolut = Mockery::mock(RevolutService::class);
    $listener = new SendApprovedOutboundPayment($revolut);
    $listener->handle(new ApprovableApproved($user));

    $connection = BankConnection::factory()->active()->create(['user_id' => $user->id]);

    // Missing bankConnection -> STATUS_FAILED
    $missingConnPayment = OutboundPayment::create([
        'team_id' => $team->id,
        'bank_connection_id' => 999999,
        'requested_by' => $user->id,
        'account_id' => 'acc-1',
        'receiver' => ['counterparty_id' => 'cp-1'],
        'amount' => 100.00,
        'currency' => 'GBP',
        'reference' => 'INV-1',
        'idempotency_key' => 'idem-1',
        'status' => OutboundPayment::STATUS_PENDING_APPROVAL,
    ]);
    $listener->handle(new ApprovableApproved($missingConnPayment));
    expect($missingConnPayment->fresh()->status)->toBe(OutboundPayment::STATUS_FAILED);

    // Happy path -> STATUS_SENT
    $revolutOk = Mockery::mock(RevolutService::class);
    $revolutOk->shouldReceive('sendPayment')->once()->andReturn(['id' => 'rev-tx-1', 'state' => 'completed']);
    $okListener = new SendApprovedOutboundPayment($revolutOk);

    $payment = OutboundPayment::create([
        'team_id' => $team->id,
        'bank_connection_id' => $connection->id,
        'requested_by' => $user->id,
        'account_id' => 'acc-1',
        'receiver' => ['counterparty_id' => 'cp-1'],
        'amount' => 250.00,
        'currency' => 'GBP',
        'reference' => 'INV-2',
        'idempotency_key' => 'idem-2',
        'status' => OutboundPayment::STATUS_PENDING_APPROVAL,
    ]);
    $okListener->handle(new ApprovableApproved($payment));
    expect($payment->fresh()->status)->toBe(OutboundPayment::STATUS_SENT)
        ->and($payment->fresh()->request_id)->toBe('idem-2')
        ->and($payment->fresh()->sent_at)->not->toBeNull();

    // Already sent -> no-op (RevolutService not called again)
    $okListener->handle(new ApprovableApproved($payment->fresh()));

    // Exception -> STATUS_FAILED
    $revolutFail = Mockery::mock(RevolutService::class);
    $revolutFail->shouldReceive('sendPayment')->once()->andThrow(new RuntimeException('API 500'));
    $failListener = new SendApprovedOutboundPayment($revolutFail);

    $failPayment = OutboundPayment::create([
        'team_id' => $team->id,
        'bank_connection_id' => $connection->id,
        'requested_by' => $user->id,
        'account_id' => 'acc-1',
        'receiver' => ['counterparty_id' => 'cp-1'],
        'amount' => 300.00,
        'currency' => 'GBP',
        'reference' => 'INV-3',
        'idempotency_key' => 'idem-3',
        'status' => OutboundPayment::STATUS_PENDING_APPROVAL,
    ]);
    $failListener->handle(new ApprovableApproved($failPayment));
    expect($failPayment->fresh()->status)->toBe(OutboundPayment::STATUS_FAILED);
});

// =========================================================================
// Notifications & Channels
// =========================================================================

test('Notifications, ResolvesChannels, and SmsChannel render and resolve channels properly', function (): void {
    [$user, $team] = makeSupportUserWithTeam('notif-owner@example.com');
    $customer = Customer::factory()->create(['customer_phone' => '+15550001111']);
    $customerNoPhone = Customer::factory()->create(['customer_phone' => '']);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'total_amount' => 150.00]);

    // User default preferences
    $payReminder = new PaymentReminderNotification($invoice);
    expect($payReminder->via($user))->toBe(['mail', 'database'])
        ->and($payReminder->via($customer))->toBe(['mail', SmsChannel::class])
        ->and($payReminder->via($customerNoPhone))->toBe(['mail'])
        ->and($payReminder->toSms($customer))->toContain('Invoice #'.$invoice->id)
        ->and($payReminder->toMail($customer)->subject)->toContain('Payment Reminder');

    // User with SMS preference enabled
    UserNotificationPreference::create([
        'user_id' => $user->id,
        'mail_enabled' => true,
        'database_enabled' => true,
        'sms_enabled' => true,
        'phone' => '+15559998888',
    ]);
    expect($payReminder->via($user->fresh()))->toBe(['mail', 'database', SmsChannel::class]);

    // CollectionNotification
    $collectionNotif = new CollectionNotification($customer);
    expect($collectionNotif->via($customer))->toBe(['mail', SmsChannel::class])
        ->and($collectionNotif->toSms($customer))->toContain('credit hold')
        ->and($collectionNotif->toMail($customer)->subject)->toBe('Important: Account Status Update');

    // ExpenseApprovalNotification (approved & rejected)
    $expense = Expense::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'amount' => 75.00,
        'description' => 'Travel',
        'date' => today(),
        'rejection_reason' => 'Missing receipt',
    ]);
    $expApproved = new ExpenseApprovalNotification($expense, 'approved');
    $expRejected = new ExpenseApprovalNotification($expense, 'rejected');
    expect($expApproved->via($user))->toContain('mail')
        ->and($expApproved->toSms($user))->toContain('approved')
        ->and($expApproved->toMail($user)->subject)->toBe('Expense approved')
        ->and($expRejected->toMail($user)->introLines)->toContain('Reason: Missing receipt')
        ->and($expApproved->toArray($user)['expense_id'])->toBe($expense->id);

    // ImportFailedNotification
    $statement = BankStatement::factory()->create();
    $importFailed = new ImportFailedNotification($statement, 'Invalid CSV');
    expect($importFailed->via($user))->toContain('mail')
        ->and($importFailed->toMail($user)->subject)->toBe('Bank statement import failed')
        ->and($importFailed->toArray($user))->toBe([
            'statement_id' => $statement->getKey(),
            'error' => 'Invalid CSV',
        ]);

    // ApprovalRequestedNotification
    $rule = ApprovalRule::create([
        'team_id' => $team->id,
        'approvable_type' => 'Expense',
        'min_amount' => 10,
        'steps' => ['manager'],
        'is_active' => true,
    ]);
    $req = ApprovalRequest::create([
        'team_id' => $team->id,
        'approvable_type' => Expense::class,
        'approvable_id' => $expense->id,
        'rule_id' => $rule->id,
        'status' => ApprovalRequest::STATUS_PENDING,
        'current_step' => 1,
    ]);
    $step = ApprovalStep::create([
        'approval_request_id' => $req->id,
        'position' => 1,
        'role' => 'manager',
        'status' => ApprovalStep::STATUS_PENDING,
    ]);
    $approvalNotif = new ApprovalRequestedNotification($step);
    expect($approvalNotif->via($user))->toContain('mail')
        ->and($approvalNotif->toSms($user))->toContain('Approval required')
        ->and($approvalNotif->toMail($user)->subject)->toContain('Approval required')
        ->and($approvalNotif->toArray($user)['approval_step_id'])->toBe($step->id);

    // PortalAccessNotification
    if (! Route::has('portal.customer.set-password')) {
        Route::get('/portal/customer/set-password/{id}/{hash}', fn () => 'ok')->name('portal.customer.set-password');
    }
    $portalNotif = new PortalAccessNotification('customer');
    expect($portalNotif->via($customer))->toBe(['mail'])
        ->and($portalNotif->toMail($customer)->subject)->toBe('Set up your portal access');

    // SmsChannel early-return guards (no external network calls)
    $smsChannel = new SmsChannel();
    $noSmsNotif = new class() extends BaseNotification {};
    $smsChannel->send($customer, $noSmsNotif); // no toSms()
    $smsChannel->send($customer, $collectionNotif); // team has no Vonage creds

    $team->forceFill([
        'vonage_key' => 'test-key',
        'vonage_secret' => 'test-secret',
        'vonage_from' => 'TestERP',
    ])->save();
    $customerNoPhone->forceFill(['team_id' => $team->id])->save();
    $smsChannel->send($customerNoPhone->fresh(), $collectionNotif); // empty phone
});

// =========================================================================
// Observers, Policies, Providers, Rules, Settings, Traits
// =========================================================================

test('InvoiceObserver and TransactionObserver write audit logs for created, updated, and deleted events', function (): void {
    $invoice = Invoice::factory()->create();
    $invoice->setAttribute('invoice_id', $invoice->id);

    $observer = new InvoiceObserver();
    $observer->created($invoice);
    $observer->updated($invoice);
    $observer->deleted($invoice);

    expect(AuditLog::where('auditable_type', Invoice::class)->where('auditable_id', $invoice->id)->count())->toBe(3);

    // TransactionObserver is bound via #[ObservedBy(TransactionObserver::class)]
    $tx = Transaction::factory()->create();
    $tx->update(['transaction_description' => 'Updated tx']);
    $tx->delete();

    expect(AuditLog::where('auditable_type', Transaction::class)->where('auditable_id', $tx->transaction_id)->count())
        ->toBeGreaterThanOrEqual(3);
});

test('BillOfMaterialsPolicy and ConnectedAccountPolicy authorize correctly', function (): void {
    [$owner, $team] = makeSupportUserWithTeam('policy-owner@example.com');
    [$other, $otherTeam] = makeSupportUserWithTeam('policy-other@example.com');

    $account = Account::factory()->create(['user_id' => $owner->id, 'team_id' => $team->id]);
    $item = InventoryItem::factory()->create(['team_id' => $team->id, 'account_id' => $account->id]);

    $bomPolicy = new BillOfMaterialsPolicy();
    $nonTeamActor = new class() extends BaseAuthenticatable {};

    expect($bomPolicy->activeTeamId(null))->toBeNull()
        ->and($bomPolicy->activeTeamId($nonTeamActor))->toBeNull()
        ->and($bomPolicy->activeTeamId($owner))->toBe($team->id)
        ->and($bomPolicy->manageBillOfMaterials($owner, $item))->toBeTrue()
        ->and($bomPolicy->manageBillOfMaterials($other, $item))->toBeFalse();

    $connected = ConnectedAccount::factory()->create(['user_id' => $owner->id]);
    $caPolicy = new ConnectedAccountPolicy();

    expect($caPolicy->viewAny($owner))->toBeTrue()
        ->and($caPolicy->create($owner))->toBeTrue()
        ->and($caPolicy->view($owner, $connected))->toBeTrue()
        ->and($caPolicy->update($owner, $connected))->toBeTrue()
        ->and($caPolicy->delete($owner, $connected))->toBeTrue()
        ->and($caPolicy->view($other, $connected))->toBeFalse();
});

test('Providers, DoubleEntryValidator, and GeneralSettings behave as expected', function (): void {
    expect(app()->getProvider(ErpServiceProvider::class))->toBeInstanceOf(ErpServiceProvider::class);

    $eventProvider = new EventServiceProvider(app());
    $eventProvider->boot();
    expect($eventProvider->shouldDiscoverEvents())->toBeFalse();

    // FortifyServiceProvider rate limiters
    $loginLimiter = RateLimiter::limiter('login');
    $twoFactorLimiter = RateLimiter::limiter('two-factor');
    $req = Request::create('/login', 'POST', ['email' => 'test@example.com']);
    $req->setLaravelSession(app('session.store'));
    $req->session()->put('login.id', 42);

    expect($loginLimiter)->toBeCallable()
        ->and($loginLimiter($req))->not->toBeNull()
        ->and($twoFactorLimiter)->toBeCallable()
        ->and($twoFactorLimiter($req))->not->toBeNull();

    // JetstreamServiceProvider roles
    expect(Jetstream::hasRoles())->toBeTrue()
        ->and(Jetstream::$roles)->toHaveKeys(['admin', 'editor']);

    // DoubleEntryValidator: array lines, object lines, legacy request input, message
    $balanced = new DoubleEntryValidator([
        ['debit_amount' => 100.00, 'credit_amount' => 0],
        (object) ['debit_amount' => 0, 'credit_amount' => 100.00],
    ]);
    $unbalanced = new DoubleEntryValidator([
        ['debit_amount' => 100.00, 'credit_amount' => 0],
        ['debit_amount' => 0, 'credit_amount' => 50.00],
    ]);

    expect($balanced->passes('lines', null))->toBeTrue()
        ->and($unbalanced->passes('lines', null))->toBeFalse()
        ->and($balanced->message())->toContain('double-entry');

    request()->merge(['debit_amount' => '25.00', 'credit_amount' => '25.00']);
    expect((new DoubleEntryValidator())->passes('amount', 25))->toBeTrue();

    // GeneralSettings compatibility class
    expect(is_subclass_of(GeneralSettings::class, SiteSettings::class))->toBeTrue();
});

// =========================================================================
// Database/Factories & Database/Seeders
// =========================================================================

test('all accounting-erp factories produce valid models and states', function (): void {
    $user = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    expect($unverified->email_verified_at)->toBeNull();

    // Exercise withPersonalTeam and withConnectedAccount factory state builders
    expect(User::factory()->withPersonalTeam())->not->toBeNull()
        ->and(User::factory()->withConnectedAccount('github'))->not->toBeNull();

    expect(Account::factory()->create())->toBeInstanceOf(Account::class)
        ->and(BankConnection::factory()->active()->withCursor()->create())->toBeInstanceOf(BankConnection::class)
        ->and(BankConnection::factory()->disconnected()->create())->toBeInstanceOf(BankConnection::class)
        ->and(BankStatement::factory()->create())->toBeInstanceOf(BankStatement::class)
        ->and(Bill::factory()->create())->toBeInstanceOf(Bill::class)
        ->and(Budget::factory()->create())->toBeInstanceOf(Budget::class)
        ->and(Company::factory()->create())->toBeInstanceOf(Company::class)
        ->and(ConnectedAccount::factory()->create(['user_id' => $user->id]))->toBeInstanceOf(ConnectedAccount::class)
        ->and(Currency::factory()->create())->toBeInstanceOf(Currency::class)
        ->and(Currency::factory()->asDefault()->create())->toBeInstanceOf(Currency::class)
        ->and(Customer::factory()->create())->toBeInstanceOf(Customer::class)
        ->and(Estimate::factory()->create())->toBeInstanceOf(Estimate::class)
        ->and(ExchangeRate::factory()->create())->toBeInstanceOf(ExchangeRate::class)
        ->and(InventoryItem::factory()->create())->toBeInstanceOf(InventoryItem::class)
        ->and(Invoice::factory()->create())->toBeInstanceOf(Invoice::class)
        ->and(JournalEntry::factory()->posted()->create())->toBeInstanceOf(JournalEntry::class)
        ->and(Menu::factory()->create())->toBeInstanceOf(Menu::class)
        ->and(Project::factory()->create())->toBeInstanceOf(Project::class)
        ->and(ReconciliationRule::factory()->create())->toBeInstanceOf(ReconciliationRule::class)
        ->and(TaxRate::factory()->create())->toBeInstanceOf(TaxRate::class)
        ->and(Transaction::factory()->create())->toBeInstanceOf(Transaction::class)
        ->and(Vendor::factory()->create())->toBeInstanceOf(Vendor::class);
});

test('all accounting-erp seeders run cleanly', function (): void {
    (new AccountTemplateSeeder())->run();
    expect(AccountTemplate::count())->toBe(3);

    // DatabaseSeeder calls MenuSeeder, TeamSeeder, RolesSeeder, UserSeeder
    (new DatabaseSeeder())->run();
    expect(Menu::count())->toBeGreaterThan(0)
        ->and(Team::where('name', 'Default')->exists())->toBeTrue()
        ->and(User::where('email', 'admin@example.com')->exists())->toBeTrue();

    // Re-run UserSeeder with configured password to cover password-update branch
    config(['app.seed_admin_password' => 'ConfiguredSeedPass123!']);
    (new UserSeeder())->run();

    // PermissionsSeeder calls Artisan::call('permissions:sync')
    Artisan::shouldReceive('call')->once()->with('permissions:sync')->andReturn(0);
    (new PermissionsSeeder())->run();
});
