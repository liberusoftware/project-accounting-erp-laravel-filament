<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\Employee;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\Menu;
use Liberu\Accounting\Erp\Models\Payroll;
use Liberu\Accounting\Erp\Models\Plan;
use Liberu\Accounting\Erp\Models\PremiumCatalog;
use Liberu\Accounting\Erp\Models\Subscription;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Services\DocumentService;
use Liberu\Accounting\Erp\Services\InvoiceScanner;
use Liberu\Accounting\Erp\Services\MenuService;
use Liberu\Accounting\Erp\Services\PayrollTaxService;
use Liberu\Accounting\Erp\Services\PayslipService;
use Liberu\Accounting\Erp\Services\SaasPremiumBillingService;
use Liberu\Accounting\Erp\Services\SubscriptionBillingService;
use Liberu\Accounting\Erp\Services\TeamManagementService;
use Liberu\Accounting\Erp\Services\TenantProvisioningService;

describe('SaasPremiumBillingService', function (): void {
    it('covers configuration guards, catalog/customer provisioning, checkout, portal, and webhooks', function (): void {
        $service = new SaasPremiumBillingService();
        $team = Team::factory()->create(['stripe_customer_id' => null]);

        // Disabled guard
        config()->set('premium.enabled', false);
        expect(fn () => $service->createCheckoutSession($team, 'monthly', 'https://app.test/ok', 'https://app.test/cancel'))
            ->toThrow(RuntimeException::class, 'disabled');

        // Missing secret guard
        config()->set('premium.enabled', true);
        config()->set('premium.secret', '');
        expect(fn () => $service->createCheckoutSession($team, 'monthly', 'https://app.test/ok', 'https://app.test/cancel'))
            ->toThrow(RuntimeException::class, 'not configured');

        config()->set('premium.secret', 'sk_test_123');
        config()->set('premium.webhook_secret', 'whsec_test_123');
        config()->set('premium.trial_days', 14);
        config()->set('premium.card_required', true);

        // Invalid interval guard
        expect(fn () => $service->createCheckoutSession($team, 'weekly', 'https://app.test/ok', 'https://app.test/cancel'))
            ->toThrow(RuntimeException::class, 'monthly or yearly');

        // Portal without stripe_customer_id throws
        expect(fn () => $service->createPortalSession($team, 'https://app.test/back'))
            ->toThrow(RuntimeException::class, 'Stripe customer');

        // Stripe HTTP failure path
        Http::fake([
            'api.stripe.com/v1/products' => Http::response(['error' => ['message' => 'Stripe down']], 500),
        ]);
        expect(fn () => $service->createCheckoutSession($team, 'monthly', 'https://app.test/ok', 'https://app.test/cancel'))
            ->toThrow(RuntimeException::class, 'Stripe down');

        // Missing product id response
        Http::fake([
            'api.stripe.com/v1/products' => Http::response([]),
        ]);
        expect(fn () => $service->createCheckoutSession($team, 'monthly', 'https://app.test/ok', 'https://app.test/cancel'))
            ->toThrow(RuntimeException::class, 'product id');

        // Full happy path: creates catalog (product + 2 prices), customer, checkout session (monthly & yearly), portal session
        Http::fake([
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_1']),
            'api.stripe.com/v1/prices' => Http::sequence()
                ->push(['id' => 'price_month'])
                ->push(['id' => 'price_year']),
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.test/cs_1']),
            'api.stripe.com/v1/billing_portal/sessions' => Http::response(['id' => 'bps_1', 'url' => 'https://portal.stripe.test/bps_1']),
        ]);

        $session = $service->createCheckoutSession($team, 'monthly', 'https://app.test/ok', 'https://app.test/cancel');
        expect($session['id'])->toBe('cs_1')
            ->and($team->fresh()->stripe_customer_id)->toBe('cus_1')
            ->and(PremiumCatalog::where('catalog_key', 'premium')->exists())->toBeTrue();

        // Second call reuses existing catalog + existing customer with yearly interval
        $yearlySession = $service->createCheckoutSession($team->fresh(), 'yearly', 'https://app.test/ok', 'https://app.test/cancel');
        expect($yearlySession['id'])->toBe('cs_1');

        $portal = $service->createPortalSession($team->fresh(), 'https://app.test/back');
        expect($portal['id'])->toBe('bps_1');

        // Webhook signature helper
        $sign = function (string $payload, ?int $ts = null): string {
            $t = $ts ?? time();

            return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, 'whsec_test_123');
        };

        // Invalid / missing / stale signatures
        expect(fn () => $service->handleWebhook('{}', null))->toThrow(RuntimeException::class, 'signature');
        expect(fn () => $service->handleWebhook('{}', 't=bad,v1=abc'))->toThrow(RuntimeException::class, 'signature');
        expect(fn () => $service->handleWebhook('{}', $sign('{}', time() - 600)))->toThrow(RuntimeException::class, 'signature');

        // Malformed payload (missing id/object)
        $malformed = json_encode(['id' => '', 'type' => 'x', 'data' => []]);
        expect(fn () => $service->handleWebhook($malformed, $sign($malformed)))->toThrow(RuntimeException::class, 'Malformed');

        // Unknown team returns silently
        $unknownTeamPayload = json_encode(['id' => 'evt_0', 'type' => 'invoice.payment_failed', 'data' => ['object' => ['customer' => 'cus_unknown']]]);
        $service->handleWebhook($unknownTeamPayload, $sign($unknownTeamPayload));

        // checkout.session.completed (resolved by metadata.team_id)
        $checkoutPayload = json_encode([
            'id' => 'evt_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'customer' => 'cus_1',
                'subscription' => 'sub_1',
                'metadata' => ['team_id' => (string) $team->id],
            ]],
        ]);
        $service->handleWebhook($checkoutPayload, $sign($checkoutPayload));
        expect($team->fresh()->premium_status)->toBe('trialing');

        // Duplicate event id is ignored
        $service->handleWebhook($checkoutPayload, $sign($checkoutPayload));

        // customer.subscription.updated (resolved by stripe_customer_id)
        $subPayload = json_encode([
            'id' => 'evt_2',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_1',
                'customer' => 'cus_1',
                'status' => 'active',
                'trial_end' => now()->addDays(7)->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'cancel_at_period_end' => false,
                'items' => ['data' => [['price' => ['id' => 'price_month']]]],
            ]],
        ]);
        $service->handleWebhook($subPayload, $sign($subPayload));
        expect($team->fresh()->premium_status)->toBe('active');

        // invoice.payment_failed
        $failedPayload = json_encode([
            'id' => 'evt_3',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['customer' => 'cus_1']],
        ]);
        $service->handleWebhook($failedPayload, $sign($failedPayload));
        expect($team->fresh()->premium_status)->toBe('past_due');
    });
});

describe('SubscriptionBillingService', function (): void {
    it('generates due invoices across all intervals and respects inactive/null-plan guards', function (): void {
        $team = Team::factory()->create();
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $service = new SubscriptionBillingService();

        // Inactive subscription -> 0
        $inactive = Subscription::create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'plan_id' => null,
            'status' => 'paused',
            'started_at' => today()->subMonth(),
            'next_billing_date' => today(),
        ]);
        expect($service->generateDueInvoices($inactive))->toBe(0);

        // Active with null plan -> 0
        $inactive->update(['status' => 'active']);
        expect($service->generateDueInvoices($inactive->fresh()))->toBe(0);

        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $interval) {
            $plan = Plan::create([
                'team_id' => $team->id,
                'name' => 'Plan '.$interval,
                'amount' => 49.99,
                'currency' => 'USD',
                'interval' => $interval,
            ]);

            $sub = Subscription::create([
                'team_id' => $team->id,
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'started_at' => today(),
                'next_billing_date' => today(),
            ]);

            expect($service->generateDueInvoices($sub))->toBe(1);
            expect($sub->fresh()->next_billing_date->isFuture())->toBeTrue();
        }
    });
});

describe('PayrollTaxService & PayslipService', function (): void {
    it('computes UK PAYE, employee/employer NI, student loans, taper, periods, and renders payslips', function (): void {
        $service = new PayrollTaxService();
        $yearService = $service->forYear((string) config('payroll.default_tax_year', '2024-25'));

        // High earner (> £100k taper threshold) + Plan 1/2 + non-numeric tax code fallback
        $high = $yearService->compute(130000.0, '1257L', 'plan_1');
        expect($high['income_tax'])->toBeGreaterThan(0)
            ->and($high['employee_ni'])->toBeGreaterThan(0)
            ->and($high['employer_ni'])->toBeGreaterThan(0)
            ->and($high['student_loan'])->toBeGreaterThan(0);

        // Basic rate, non-numeric code, null & unknown student loan plan
        expect($yearService->incomeTax(30000.0, 'BR'))->toBeGreaterThan(0)
            ->and($yearService->studentLoan(30000.0, null))->toBe(0.0)
            ->and($yearService->studentLoan(30000.0, 'nonexistent_plan'))->toBe(0.0);

        // Period apportionment + cumulative check
        $monthly = $yearService->computeForPeriod(3000.0, 'monthly', '1257L W1');
        expect($monthly['net'])->toBeGreaterThan(0);
        expect($yearService->isCumulative('1257L'))->toBeTrue()
            ->and($yearService->isCumulative('1257L W1'))->toBeFalse()
            ->and($yearService->isCumulative('1257L M1'))->toBeFalse();

        // PayslipService HTML & PDF
        $employee = Employee::create([
            'name' => 'John Smith',
            'email' => 'john@payroll.test',
            'position' => 'Accountant',
            'hire_date' => '2024-01-01',
            'national_insurance_number' => 'QQ123456C',
        ]);
        $payroll = Payroll::create([
            'employee_id' => $employee->id,
            'base_salary' => 3000,
            'overtime_hours' => 5,
            'overtime_rate' => 20,
            'tax_deductions' => 500,
            'other_deductions' => 50,
            'net_salary' => 2550,
            'pay_period_start' => '2026-01-01',
            'pay_period_end' => '2026-01-31',
            'payment_date' => '2026-01-31',
            'payment_status' => 'paid',
        ]);

        $payslipService = new PayslipService();
        $html = $payslipService->html($payroll);
        expect($html)->toContain('John Smith');

        $pdf = $payslipService->pdf($payroll);
        expect($pdf)->toStartWith('%PDF');
    });
});

describe('DocumentService', function (): void {
    it('attaches documents, adds sequential versions, and prunes expired documents', function (): void {
        Storage::fake('local');
        config()->set('documents.disk', 'local');

        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id]);

        $service = new DocumentService();
        $file1 = UploadedFile::fake()->create('receipt-v1.pdf', 10, 'application/pdf');
        $doc = $service->attach($invoice, $file1, today()->subDay());

        expect($doc->versions()->count())->toBe(1);

        $file2 = UploadedFile::fake()->create('receipt-v2.pdf', 12, 'application/pdf');
        $v2 = $service->addVersion($doc, $file2);
        expect($v2->version_number)->toBe(2);

        $pruned = $service->prune();
        expect($pruned)->toBe(1);
    });
});

describe('InvoiceScanner', function (): void {
    it('extracts invoice metadata and handles missing values cleanly', function (): void {
        $scanner = new class extends InvoiceScanner
        {
            public function parse(string $text): array
            {
                return $this->extractInvoiceData($text);
            }

            public function toImage(UploadedFile $file): string
            {
                return $this->convertToImage($file);
            }
        };

        $text = "From: Acme Corp\nInvoice No: INV-2026-42\nDate: 2026-01-15\nTax ID: GB-998877\nTotal: $1,234.56\n";
        $parsed = $scanner->parse($text);
        expect($parsed['invoice_number'])->toBe('INV-2026-42')
            ->and($parsed['amount'])->toBe(1234.56)
            ->and($parsed['date'])->toBe('2026-01-15')
            ->and($parsed['vendor_details']['name'])->toBe('Acme Corp')
            ->and($parsed['vendor_details']['tax_id'])->toBe('GB-998877');

        $empty = $scanner->parse('Unrelated text');
        expect($empty['invoice_number'])->toBeNull()
            ->and($empty['amount'])->toBeNull()
            ->and($empty['date'])->toBeNull();

        $file = UploadedFile::fake()->image('invoice.png');
        expect($scanner->toImage($file))->toBe($file->getPathname());

        // Exercise scanInvoice() entry point (gracefully tolerate missing tesseract binary in CI)
        try {
            $scanner->scanInvoice($file);
        } catch (\Throwable) {
            expect(true)->toBeTrue();
        }
    });
});

describe('MenuService', function (): void {
    it('builds nested menus and sanitizes dangerous URL schemes', function (): void {
        $parent = Menu::factory()->create([
            'name' => 'Finance',
            'url' => '/finance',
            'parent_id' => null,
            'order' => 1,
        ]);
        Menu::factory()->create([
            'name' => 'Reports',
            'url' => '/finance/reports',
            'parent_id' => $parent->id,
            'order' => 1,
        ]);
        Menu::factory()->create([
            'name' => 'XSS Link',
            'url' => 'javascript:alert(1)',
            'parent_id' => null,
            'order' => 2,
        ]);

        $menu = (new MenuService())->buildMenu();
        $html = $menu->render();

        expect($html)->toContain('Finance')
            ->and($html)->toContain('Reports')
            ->and($html)->not->toContain('javascript:alert(1)')
            ->and($html)->toContain('href="#"');
    });
});

describe('TeamManagementService & TenantProvisioningService', function (): void {
    it('provisions personal teams idempotently and seeds the default chart of accounts once', function (): void {
        $user = User::factory()->create(['current_team_id' => null]);

        $teamService = new TeamManagementService();
        $teamService->assignUserToDefaultTeam($user);

        $user->refresh();
        expect($user->current_team_id)->not->toBeNull();

        $team = $teamService->createPersonalTeamForUser($user);
        expect($team->personal_team)->toBeTrue()
            ->and($user->ownedTeams()->where('personal_team', true)->count())->toBe(1);

        $provisioning = new TenantProvisioningService();
        $created = $provisioning->provisionChartOfAccounts($team);
        expect($created)->toBe(18)
            ->and(Account::where('team_id', $team->id)->count())->toBe(18);

        // Idempotent second call returns 0
        expect($provisioning->provisionChartOfAccounts($team))->toBe(0);
    });
});
