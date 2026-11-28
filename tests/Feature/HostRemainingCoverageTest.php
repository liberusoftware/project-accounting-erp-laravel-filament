<?php

declare(strict_types=1);

use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema as DbSchema;
use Liberu\Accounting\Erp\Concerns\Recurring;
use Liberu\Accounting\Erp\Database\Seeders\UserSeeder;
use Liberu\Accounting\Erp\Jobs\SyncPlaidTransactionsJob;
use Liberu\Accounting\Erp\Models\ApprovalRule;
use Liberu\Accounting\Erp\Models\ApprovalStep;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\Bill;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\DelayedCharge;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\JournalEntry;
use Liberu\Accounting\Erp\Models\Payment;
use Liberu\Accounting\Erp\Models\TaxRate;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Models\Vendor;
use Liberu\Accounting\Erp\Models\VendorCredit;
use Liberu\Accounting\Erp\Notifications\Channels\SmsChannel;
use Liberu\Accounting\Erp\Providers\FortifyServiceProvider;
use Liberu\Accounting\Erp\Services\AgingReportService;
use Liberu\Accounting\Erp\Services\ApprovalService;
use Liberu\Accounting\Erp\Services\CollectionService;
use Liberu\Accounting\Erp\Services\FinancialStatementService;
use Liberu\Accounting\Erp\Services\InvoicePostingService;
use Liberu\Accounting\Erp\Services\InvoiceScanner;
use Liberu\Accounting\Erp\Services\PaymentPostingService;
use Liberu\Accounting\Erp\Services\PlaidService;
use Liberu\Accounting\Erp\Services\PostingReversalService;
use Liberu\Accounting\Erp\Services\RevolutService;
use Liberu\Accounting\Erp\Services\SaasPremiumBillingService;
use Liberu\Accounting\Erp\Services\TeamManagementService;
use Liberu\Accounting\Erp\Services\TenantProvisioningService;
use Liberu\Accounting\Erp\Services\WiseService;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\AccountSetupWizard;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ApprovalRules\ApprovalRuleResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Bills\BillResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\JournalEntries\Pages\EditJournalEntry;
use Livewire\Livewire;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Spatie\Permission\Models\Role;

it('covers EditJournalEntry beforeValidate notification and halt for posted entries', function (): void {
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);

    $entry = new JournalEntry();
    $entry->forceFill([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'entry_number' => 'JE-POSTED-COV',
        'entry_date' => now()->toDateString(),
        'entry_type' => 'general',
        'is_posted' => true,
    ])->save();

    $page = new class() extends EditJournalEntry
    {
        public function callBeforeValidate(): void
        {
            $this->beforeValidate();
        }
    };
    $page->record = $entry;

    expect(fn () => $page->callBeforeValidate())->toThrow(Halt::class);
});

it('covers SyncPlaidTransactionsJob catch block when marking connection requires_reauth fails', function (): void {
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $connection = BankConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $plaid = Mockery::mock(PlaidService::class);
    $plaid->shouldReceive('syncTransactions')
        ->once()
        ->andThrow(new Exception('User action required to reauthenticate'));

    $targetId = $connection->id;
    BankConnection::updating(static function (BankConnection $model) use ($targetId): void {
        if ($model->id === $targetId) {
            throw new Exception('Simulated DB update failure');
        }
    });

    $job = new SyncPlaidTransactionsJob($connection->id);

    expect(fn () => $job->handle($plaid))
        ->toThrow(Exception::class, 'User action required to reauthenticate');
});

it('covers AgingReportService 31_60, 61_90, and over_90 buckets', function (): void {
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $customer = Customer::factory()->create([
        'team_id' => $team->id,
        'customer_name' => 'Aging Buckets Customer',
    ]);

    foreach ([45, 75, 110] as $days) {
        Invoice::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'payment_status' => 'pending',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays($days)->toDateString(),
            'total_amount' => 100.00,
        ]);
    }

    $prevMissing = Model::preventsAccessingMissingAttributes();
    Model::preventAccessingMissingAttributes(false);
    try {
        $report = (new AgingReportService())->generateAgingReport();
    } finally {
        Model::preventAccessingMissingAttributes($prevMissing);
    }
    $row = $report->firstWhere('customer_name', 'Aging Buckets Customer');

    expect($row)->not->toBeNull()
        ->and((float) $row['aging']['31_60'])->toBeGreaterThan(0.0)
        ->and((float) $row['aging']['61_90'])->toBeGreaterThan(0.0)
        ->and((float) $row['aging']['over_90'])->toBeGreaterThan(0.0);
});

it('covers SmsChannel sending via injected PSR-18 client', function (): void {
    $user = User::factory()->create();
    $team = Team::forceCreate([
        'user_id' => $user->id,
        'name' => 'SMS Team',
        'personal_team' => true,
        'vonage_key' => 'test-key',
        'vonage_secret' => 'test-secret',
        'vonage_from' => 'LiberuERP',
    ]);

    $notifiable = new class($team->id)
    {
        public function __construct(public int $team_id) {}

        public function routeNotificationFor(string $driver, BaseNotification $notification): string
        {
            return '+447700900123';
        }
    };

    $notification = new class() extends BaseNotification
    {
        public function toSms(mixed $notifiable): string
        {
            return 'Hello from accounting ERP';
        }
    };

    $stubHttp = new class() implements ClientInterface
    {
        public int $requests = 0;

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->requests++;

            return new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
                'message-count' => '1',
                'messages' => [[
                    'to' => '447700900123',
                    'message-id' => 'cov-msg-1',
                    'status' => '0',
                    'remaining-balance' => '10.00',
                    'message-price' => '0.03',
                    'network' => '23410',
                ]],
            ], JSON_THROW_ON_ERROR));
        }
    };

    (new SmsChannel($stubHttp))->send($notifiable, $notification);

    expect($stubHttp->requests)->toBe(1);
});

it('covers BillResource tax_rate_id afterStateUpdated callback', function (): void {
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $taxRate = TaxRate::forceCreate([
        'team_id' => $team->id,
        'name' => 'VAT 20',
        'rate' => 20.0,
        'is_active' => true,
    ]);

    $this->actingAs($user);

    $schema = BillResource::form(Schema::make());
    $taxSelect = null;

    $walk = static function (array $components) use (&$walk, &$taxSelect): void {
        foreach ($components as $component) {
            if (method_exists($component, 'getName') && $component->getName() === 'tax_rate_id') {
                $ref = new ReflectionProperty($component, 'afterStateUpdated');
                if (! empty($ref->getValue($component))) {
                    $taxSelect = $component;

                    return;
                }
            }
            if (method_exists($component, 'getDefaultChildComponents')) {
                $walk($component->getDefaultChildComponents());
            }
        }
    };
    $walk($schema->getComponents());

    expect($taxSelect)->not->toBeNull();

    $ref = new ReflectionProperty($taxSelect, 'afterStateUpdated');
    $rawCallbacks = $ref->getValue($taxSelect);

    $captured = [];
    $set = static function (string $key, mixed $value) use (&$captured): void {
        $captured[$key] = $value;
    };
    $get = static fn (string $key): mixed => $key === 'subtotal_amount' ? 250.0 : null;

    $invoke = static function (mixed $item) use (&$invoke, $taxRate, $set, $get): void {
        if ($item instanceof Closure) {
            $item($taxRate->getKey(), $set, $get);
        } elseif (is_array($item)) {
            foreach ($item as $child) {
                $invoke($child);
            }
        }
    };
    $invoke($rawCallbacks);

    expect((float) $captured['tax_amount'])->toBe(50.0);
});

it('covers InvoicePostingService and PaymentPostingService locked idempotency branches', function (): void {
    $user = User::factory()->create();
    $team = Team::forceCreate([
        'user_id' => $user->id,
        'name' => 'Lock Race Team',
        'personal_team' => false,
    ]);
    app(TenantProvisioningService::class)->provisionChartOfAccounts($team);

    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $invoice = Invoice::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'invoice_date' => '2026-06-01',
        'total_amount' => 200.00,
    ]);

    $staleInvoice = clone $invoice;
    $postedInvoiceEntry = app(InvoicePostingService::class)->post($invoice);

    $resolvedInvoiceEntry = app(InvoicePostingService::class)->post($staleInvoice);
    expect($resolvedInvoiceEntry->id)->toBe($postedInvoiceEntry->id);

    $payment = Payment::forceCreate([
        'team_id' => $team->id,
        'invoice_id' => $invoice->id,
        'payment_date' => '2026-06-02',
        'payment_amount' => 200.00,
    ]);

    $stalePayment = clone $payment;
    $postedPaymentEntry = app(PaymentPostingService::class)->post($payment);

    $resolvedPaymentEntry = app(PaymentPostingService::class)->post($stalePayment);
    expect($resolvedPaymentEntry->id)->toBe($postedPaymentEntry->id);
});

it('covers PostingReversalService missing linked journal entry branches', function (): void {
    $user = User::factory()->create();
    $team = Team::forceCreate([
        'user_id' => $user->id,
        'name' => 'Missing Entry Team',
        'personal_team' => false,
    ]);
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $invoice = Invoice::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'total_amount' => 100.00,
    ]);
    $invoice->journal_entry_id = 999999;
    $invoice->setRelation('journalEntry', null);

    $payment = Payment::forceCreate([
        'team_id' => $team->id,
        'invoice_id' => $invoice->id,
        'payment_date' => '2026-06-02',
        'payment_amount' => 100.00,
    ]);
    $payment->journal_entry_id = 999999;
    $payment->setRelation('journalEntry', null);

    $service = app(PostingReversalService::class);

    expect(fn () => $service->reverseInvoice($invoice))
        ->toThrow(RuntimeException::class, 'Linked journal entry is missing.');
    expect(fn () => $service->reversePayment($payment))
        ->toThrow(RuntimeException::class, 'Linked journal entry is missing.');
});

it('covers DelayedCharge default status on creation and VendorCredit default status', function (): void {
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $customer = Customer::factory()->create(['team_id' => $team->id]);

    $charge = DelayedCharge::forceCreate([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'charge_date' => now()->toDateString(),
        'description' => 'Consulting',
        'quantity' => 2,
        'unit_price' => 50.00,
        'status' => '',
    ]);

    expect($charge->status)->toBe('pending');

    $vendor = Vendor::factory()->create(['team_id' => $team->id]);
    $credit = VendorCredit::forceCreate([
        'team_id' => $team->id,
        'vendor_id' => $vendor->vendor_id,
        'credit_date' => now()->toDateString(),
        'subtotal_amount' => 100.00,
        'tax_amount' => 0.00,
        'total_amount' => 100.00,
        'amount_applied' => 0.00,
        'status' => '',
    ]);

    expect($credit->fresh()->status)->toBe('open');
});

it('covers FortifyServiceProvider login rate limiter callback', function (): void {
    (new FortifyServiceProvider(app()))->boot();

    $limiter = RateLimiter::limiter('login');
    expect($limiter)->not->toBeNull();

    $request = Request::create('/login', 'POST', ['email' => 'user@example.com']);
    $limit = $limiter($request);

    expect($limit->maxAttempts)->toBe(5);
});

it('covers PlaidService, RevolutService, WiseService, and SaasPremiumBillingService error branches', function (): void {
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);

    config([
        'services.plaid.client_id' => 'cid',
        'services.plaid.secret' => 'sec',
        'services.plaid.env' => 'sandbox',
        'services.revolut.client_id' => 'rcid',
        'services.revolut.client_secret' => 'rsec',
        'services.revolut.sandbox' => true,
        'services.wise.api_token' => 'wtok',
        'services.wise.sandbox' => true,
        'services.stripe.secret' => 'sk_test_123',
        'premium.enabled' => true,
        'premium.secret' => 'sk_test_123',
        'premium.monthly_amount' => 1900,
        'premium.yearly_amount' => 19000,
        'premium.currency' => 'gbp',
    ]);

    Http::fake([
        'https://sandbox.plaid.com/*' => Http::response(['error' => 'redirect'], 302),
        'https://sandbox-b2b.revolut.com/*' => Http::response(['error' => 'redirect'], 302),
        'https://api.sandbox.transferwise.tech/*' => Http::response(['error' => 'redirect'], 302),
        'https://api.stripe.com/v1/products' => Http::response(['id' => 'prod_123'], 200),
        'https://api.stripe.com/v1/prices' => Http::response(['id' => 'price_123'], 200),
        'https://api.stripe.com/v1/customers' => Http::response([], 200),
    ]);

    expect(fn () => (new PlaidService())->createLinkToken($user->id))
        ->toThrow(Exception::class, 'Failed to create link token');

    $connection = BankConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'revolut_access_token' => 'tok-123',
        'revolut_token_expires_at' => now()->addHour(),
        'wise_access_token' => 'wtok-123',
        'wise_token_expires_at' => now()->addHour(),
    ]);

    expect(fn () => (new RevolutService())->getTransactions($connection))
        ->toThrow(Exception::class, 'Failed to get transactions');

    expect(fn () => (new WiseService())->getTransfers($connection, 1))
        ->toThrow(Exception::class, 'Failed to get transfers');

    expect(fn () => app(SaasPremiumBillingService::class)->createCheckoutSession($team, 'month', 'https://example.test/ok', 'https://example.test/cancel'))
        ->toThrow(RuntimeException::class, 'Stripe did not return a customer id.');
});

it('covers FinancialStatementService getAccountBalance when account does not exist', function (): void {
    $service = new class() extends FinancialStatementService
    {
        public function callGetAccountBalance(int $accountId, ?Carbon $start, Carbon $end): float
        {
            return $this->getAccountBalance($accountId, $start, $end);
        }
    };

    expect($service->callGetAccountBalance(999999, null, now()))->toBe(0.0);
});

it('covers AccountSetupWizard save when team has no existing integrations array', function (): void {
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $team->forceFill(['accounting_setup' => null])->save();
    $user->unsetRelation('currentTeam');

    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant(null);

    Livewire::test(AccountSetupWizard::class)
        ->fillForm([
            'business_name' => 'Fresh Team',
            'country' => 'GB',
            'currency' => 'GBP',
            'fiscal_year_start' => '01-01',
            'timezone' => 'UTC',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($team->fresh()->accounting_setup_completed_at)->not->toBeNull();
});

it('covers Recurring default recurringDraftAttributes method', function (): void {
    $stub = new class()
    {
        use Recurring;

        protected function recurringDateColumns(Carbon $date): array
        {
            return [];
        }

        public function callDefaultDraftAttributes(): array
        {
            return $this->recurringDraftAttributes();
        }
    };

    expect($stub->callDefaultDraftAttributes())->toBe([]);
});

it('covers ApprovalService canAct returning false when step status is neither pending nor escalated', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    setPermissionsTeamId($team->id);
    $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web', 'team_id' => $team->id]);
    $user->assignRole($role);

    ApprovalRule::create([
        'team_id' => $team->id,
        'approvable_type' => 'Bill',
        'min_amount' => 0,
        'steps' => ['manager'],
        'is_active' => true,
    ]);

    $bill = Bill::factory()->create(['team_id' => $team->id, 'total_amount' => 1000, 'approval_status' => 'draft']);
    $bill->submitForApproval();

    $step = $bill->approvalRequest()->first()->steps()->first();
    $step->status = ApprovalStep::STATUS_APPROVED;

    expect(app(ApprovalService::class)->canAct($step, $user))->toBeFalse();
});

it('covers InvoiceScanner scanInvoice using a fake tesseract binary in PATH', function (): void {
    $binDir = sys_get_temp_dir().'/fake-tesseract-'.uniqid();
    mkdir($binDir, 0755, true);
    $script = $binDir.'/tesseract';
    file_put_contents($script, "#!/bin/sh\nif [ \"\$1\" = \"--version\" ]; then echo \"tesseract 5.0.0\"; exit 0; fi\nprintf 'Invoice No: INV-999\\nTotal: \$250.50\\nDate: 2026-06-01\\nFrom: Acme Ltd\\nTax ID: GB12345\\n' > \"\$2.txt\"\n");
    chmod($script, 0755);

    $origPath = getenv('PATH') ?: '';
    putenv('PATH='.$binDir.PATH_SEPARATOR.$origPath);

    try {
        $file = UploadedFile::fake()->image('invoice.png');
        $result = (new InvoiceScanner())->scanInvoice($file);

        expect($result['invoice_number'])->toBe('INV-999')
            ->and($result['amount'])->toBe(250.5);
    } finally {
        putenv('PATH='.$origPath);
        @unlink($script);
        @rmdir($binDir);
    }
});

it('covers CollectionService notifying overdue customers with email', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $customer = Customer::factory()->create([
        'team_id' => $team->id,
        'customer_email' => 'overdue@example.com',
        'credit_hold' => false,
    ]);

    Invoice::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'payment_status' => 'pending',
        'due_date' => now()->subDays(120)->toDateString(),
        'total_amount' => 500.00,
    ]);

    (new CollectionService())->flagDelinquentAccounts();

    expect((bool) $customer->fresh()->credit_hold)->toBeTrue();
});

it('covers BackfillTenancy continue branch when a user-owned table lacks team_id or user_id', function (): void {
    $user = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($user);

    DbSchema::shouldReceive('hasColumn')->andReturn(false);

    $this->artisan('teams:backfill-tenancy', ['--dry-run' => true])
        ->assertSuccessful();
});

it('covers UserSeeder local environment console password output', function (): void {
    $owner = User::factory()->create();
    $team = Team::forceCreate([
        'user_id' => $owner->id,
        'name' => 'Seed Team',
        'personal_team' => true,
    ]);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => $team->id]);

    DB::table('users')->where('email', 'admin@example.com')->delete();

    $origEnv = app()->environment();
    config(['app.seed_admin_password' => '']);
    app()->detectEnvironment(static fn (): string => 'local');

    try {
        Model::unguarded(static function (): void {
            (new UserSeeder())->run();
        });

        expect(User::where('email', 'admin@example.com')->exists())->toBeTrue();
    } finally {
        app()->detectEnvironment(static fn (): string => $origEnv);
    }
});

it('covers ApprovalRuleResource approvableTypeOptions and form schema', function (): void {
    $schema = ApprovalRuleResource::form(Schema::make());

    expect($schema->getComponents())->not->toBeEmpty();
});
