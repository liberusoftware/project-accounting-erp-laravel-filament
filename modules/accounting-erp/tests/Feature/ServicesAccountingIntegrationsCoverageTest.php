<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\Bill;
use Liberu\Accounting\Erp\Models\CreditMemo;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\Estimate;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\Payment;
use Liberu\Accounting\Erp\Models\QboConnection;
use Liberu\Accounting\Erp\Models\SageConnection;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Models\Vendor;
use Liberu\Accounting\Erp\Models\XeroConnection;
use Liberu\Accounting\Erp\Services\Concerns\ResolvesSyncedContacts;
use Liberu\Accounting\Erp\Services\ProviderTransactionImporter;
use Liberu\Accounting\Erp\Services\QuickBooksService;
use Liberu\Accounting\Erp\Services\SageService;
use Liberu\Accounting\Erp\Services\XeroService;

function configureAccountingProviders(): void
{
    config()->set('services.qbo', [
        'client_id' => 'qbo-client',
        'client_secret' => 'qbo-secret',
        'redirect_uri' => 'https://app.test/qbo/callback',
        'authorization_url' => 'https://appcenter.intuit.com/connect/oauth2',
        'token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        'api_base_url' => 'https://sandbox-quickbooks.api.intuit.com',
    ]);

    config()->set('services.xero', [
        'client_id' => 'xero-client',
        'client_secret' => 'xero-secret',
        'redirect_uri' => 'https://app.test/xero/callback',
        'authorization_url' => 'https://login.xero.com/identity/connect/authorize',
        'token_url' => 'https://identity.xero.com/connect/token',
        'connections_url' => 'https://api.xero.com/connections',
        'api_base_url' => 'https://api.xero.com/api.xro/2.0',
    ]);

    config()->set('services.sage', [
        'client_id' => 'sage-client',
        'client_secret' => 'sage-secret',
        'redirect_uri' => 'https://app.test/sage/callback',
        'authorization_url' => 'https://www.sageone.com/oauth2/auth/central',
        'token_url' => 'https://oauth.accounting.sage.com/token',
        'businesses_url' => 'https://api.accounting.sage.com/v3.1/businesses',
        'api_base_url' => 'https://api.accounting.sage.com/v3.1',
    ]);
}

describe('ProviderTransactionImporter & Concerns', function (): void {
    it('imports, updates, validates external ids, enforces team isolation, and resolves account ids', function (): void {
        $importer = app(ProviderTransactionImporter::class);
        $team1 = Team::factory()->create();
        $team2 = Team::factory()->create();

        $account = Account::factory()->create([
            'team_id' => $team1->id,
            'qbo_id' => 'qbo-acc-1',
            'xero_id' => 'xero-acc-1',
            'sage_id' => 'sage-acc-1',
        ]);

        expect($importer->accountId('qbo_id', 'qbo-acc-1', $team1->id))->toBe($account->id)
            ->and($importer->accountId('xero_id', 'xero-acc-1', $team1->id))->toBe($account->id)
            ->and($importer->accountId('sage_id', 'sage-acc-1', $team1->id))->toBe($account->id)
            ->and($importer->accountId('qbo_id', 'missing', $team1->id))->toBeNull()
            ->and($importer->accountId('qbo_id', null, $team1->id))->toBeNull()
            ->and($importer->accountId('qbo_id', '', $team1->id))->toBeNull();

        expect(fn () => $importer->accountId('invalid_provider', '123', $team1->id))
            ->toThrow(InvalidArgumentException::class, 'Unsupported accounting provider.');

        $count = $importer->handle([
            ['id' => 'ext-1', 'amt' => 125.50],
        ], $team1->id, fn (array $row): array => [
            'external_id' => $row['id'],
            'date' => '2026-01-15',
            'description' => 'Initial import',
            'amount' => $row['amt'],
            'account_id' => $account->id,
            'type' => 'deposit',
        ]);

        expect($count)->toBe(1);
        expect(Transaction::where('external_id', 'ext-1')->value('description'))->toBe('Initial import');

        // Re-importing updates same team record
        $importer->handle([
            ['id' => 'ext-1', 'amt' => 200.00],
        ], $team1->id, fn (array $row): array => [
            'external_id' => $row['id'],
            'date' => '2026-01-16',
            'description' => 'Updated import',
            'amount' => $row['amt'],
            'account_id' => $account->id,
            'type' => 'deposit',
        ]);
        expect(Transaction::where('external_id', 'ext-1')->value('description'))->toBe('Updated import');

        // Empty external_id throws InvalidArgumentException
        expect(fn () => $importer->handle([['id' => '']], $team1->id, fn (): array => [
            'external_id' => '',
            'date' => '2026-01-16',
            'description' => 'Bad',
            'amount' => 10,
            'account_id' => null,
            'type' => 'deposit',
        ]))->toThrow(InvalidArgumentException::class);

        // Cross-team collision throws LogicException
        expect(fn () => $importer->handle([['id' => 'ext-1']], $team2->id, fn (): array => [
            'external_id' => 'ext-1',
            'date' => '2026-01-16',
            'description' => 'Cross-team',
            'amount' => 10,
            'account_id' => null,
            'type' => 'deposit',
        ]))->toThrow(LogicException::class);
    });

    it('resolves synced contacts with custom provider tag fallback and null teamId', function (): void {
        $helper = new class
        {
            use ResolvesSyncedContacts;

            public function customer(string $name, string $tag, ?int $teamId = null): int
            {
                return $this->syncedCustomerId($name, $tag, $teamId);
            }

            public function vendor(string $name, string $tag, ?int $teamId = null): int
            {
                return $this->syncedVendorId($name, $tag, $teamId);
            }
        };

        $team = Team::factory()->create();
        $cid1 = $helper->customer('Acme Custom', 'custom', $team->id);
        $cid2 = $helper->customer('Acme Null Team', 'qbo', null);
        $vid1 = $helper->vendor('Vendor Custom', 'custom', $team->id);
        $vid2 = $helper->vendor('Vendor Null Team', 'xero', null);

        expect($cid1)->toBeInt()
            ->and($cid2)->toBeInt()
            ->and($vid1)->toBeInt()
            ->and($vid2)->toBeInt()
            ->and(Customer::find($cid1)?->customer_address)->toBe('Imported from Custom');
    });
});

describe('QuickBooksService', function (): void {
    beforeEach(function (): void {
        configureAccountingProviders();
    });

    it('builds authorization url, handles callback, and refreshes expired tokens', function (): void {
        Http::fake([
            'oauth.platform.intuit.com/*' => Http::response([
                'access_token' => 'qbo-new-access',
                'refresh_token' => 'qbo-new-refresh',
                'expires_in' => 3600,
            ]),
            'sandbox-quickbooks.api.intuit.com/*' => Http::response([
                'QueryResponse' => ['Account' => []],
            ]),
        ]);

        $user = User::factory()->create();
        $service = new QuickBooksService();

        $url = $service->getAuthorizationUrl('state-123');
        expect($url)->toContain('client_id=qbo-client')
            ->and($url)->toContain('state=state-123')
            ->and($url)->toContain('com.intuit.quickbooks.accounting');

        $connection = $service->handleCallback($user->id, 'auth-code-1', 'realm-99');
        expect($connection)->toBeInstanceOf(QboConnection::class)
            ->and($connection->realm_id)->toBe('realm-99')
            ->and($connection->access_token)->toBe('qbo-new-access')
            ->and($connection->status)->toBe('active');

        // Force token expiry to cover refreshIfNeeded
        $connection->update(['token_expires_at' => now()->subMinute()]);
        expect($service->pullAccounts($connection->fresh()))->toBe(0);
    });

    it('pushes all QBO entities (both create and sparse update paths)', function (): void {
        Http::fake([
            '*/invoice' => Http::response(['Invoice' => ['Id' => 'QBO-INV-1', 'SyncToken' => '2']]),
            '*/estimate' => Http::response(['Estimate' => ['Id' => 'QBO-EST-1']]),
            '*/creditmemo' => Http::response(['CreditMemo' => ['Id' => 'QBO-CM-1']]),
            '*/customer' => Http::response(['Customer' => ['Id' => 'QBO-CUST-1']]),
            '*/vendor' => Http::response(['Vendor' => ['Id' => 'QBO-VEND-1']]),
            '*/account' => Http::response(['Account' => ['Id' => 'QBO-ACC-1', 'SyncToken' => '1']]),
            '*/bill' => Http::response(['Bill' => ['Id' => 'QBO-BILL-1', 'SyncToken' => '3']]),
            '*/payment' => Http::response(['Payment' => ['Id' => 'QBO-PAY-1', 'SyncToken' => '4']]),
        ]);

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $conn = QboConnection::create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'realm_id' => 'realm-1',
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        $service = new QuickBooksService();

        $customer = Customer::factory()->create(['team_id' => $team->id, 'qbo_id' => null]);
        $service->pushCustomer($customer, $conn);
        expect($customer->fresh()->qbo_id)->toBe('QBO-CUST-1');
        // Sparse update path
        $service->pushCustomer($customer->fresh(), $conn);

        $vendor = Vendor::factory()->create(['team_id' => $team->id, 'qbo_id' => null]);
        $service->pushVendor($vendor, $conn);
        expect($vendor->fresh()->qbo_id)->toBe('QBO-VEND-1');
        $service->pushVendor($vendor->fresh(), $conn);

        $account = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'revenue', 'qbo_id' => null]);
        $service->pushAccount($account, $conn);
        expect($account->fresh()->qbo_id)->toBe('QBO-ACC-1');
        $service->pushAccount($account->fresh(), $conn);

        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'qbo_id' => null]);
        $service->pushInvoice($invoice, $conn);
        expect($invoice->fresh()->qbo_id)->toBe('QBO-INV-1');
        $service->pushInvoice($invoice->fresh(), $conn);

        $estimate = Estimate::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'qbo_id' => null]);
        $service->pushEstimate($estimate, $conn);
        expect($estimate->fresh()->qbo_id)->toBe('QBO-EST-1');
        $service->pushEstimate($estimate->fresh(), $conn);

        $creditMemo = CreditMemo::create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'credit_memo_number' => 'CM-100',
            'credit_memo_date' => '2026-01-10',
            'subtotal_amount' => 50,
            'total_amount' => 50,
            'status' => 'open',
        ]);
        $service->pushCreditMemo($creditMemo, $conn);
        expect($creditMemo->fresh()->qbo_id)->toBe('QBO-CM-1');
        $service->pushCreditMemo($creditMemo->fresh(), $conn);

        $bill = Bill::factory()->create(['team_id' => $team->id, 'vendor_id' => $vendor->vendor_id, 'qbo_id' => null]);
        $service->pushBill($bill, $conn);
        expect($bill->fresh()->qbo_id)->toBe('QBO-BILL-1');
        $service->pushBill($bill->fresh(), $conn);

        $payment = Payment::create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'payment_amount' => 100.00,
            'payment_date' => '2026-01-12',
        ]);
        $service->pushPayment($payment, $conn);
        expect($payment->fresh()->qbo_id)->toBe('QBO-PAY-1');
        $service->pushPayment($payment->fresh(), $conn);
    });

    it('syncs and pulls all QBO entities including edge cases', function (): void {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $conn = QboConnection::create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'realm_id' => 'realm-2',
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        // Pre-seed one customer and vendor with qbo_id to hit matched lookup branches
        $existingCustomer = Customer::factory()->create(['team_id' => $team->id, 'qbo_id' => 'C-EXISTING']);
        $existingVendor = Vendor::factory()->create(['team_id' => $team->id, 'qbo_id' => 'V-EXISTING']);

        Http::fake(function ($request) {
            $q = $request['query'] ?? '';

            return match (true) {
                str_contains($q, 'from Customer') => Http::response(['QueryResponse' => ['Customer' => [
                    ['Id' => 'C1', 'DisplayName' => 'Alice QBO', 'PrimaryEmailAddr' => ['Address' => 'alice@qbo.test']],
                    ['DisplayName' => 'No ID skipped'],
                ]]]),
                str_contains($q, 'from Vendor') => Http::response(['QueryResponse' => ['Vendor' => [
                    ['Id' => 'V1', 'DisplayName' => 'Bob Vendor'],
                    ['DisplayName' => 'No ID skipped'],
                ]]]),
                str_contains($q, 'from Account') => Http::response(['QueryResponse' => ['Account' => [
                    ['Id' => '10', 'Name' => 'Bank QBO', 'Classification' => 'Asset', 'AcctNum' => '1010', 'SyncToken' => '0'],
                    ['Id' => '11', 'Name' => 'Misc QBO', 'Classification' => 'Expense', 'AcctNum' => 'NON-NUM'],
                ]]]),
                str_contains($q, 'from Invoice') => Http::response(['QueryResponse' => ['Invoice' => [
                    ['Id' => 'INV-1', 'DocNumber' => '1001', 'CustomerRef' => ['value' => 'C-EXISTING', 'name' => 'Existing'], 'TotalAmt' => 250, 'TxnDate' => '2026-01-10'],
                    ['Id' => 'INV-2', 'CustomerRef' => ['value' => 'C-NEW', 'name' => 'Brand New'], 'TotalAmt' => 100],
                ]]]),
                str_contains($q, 'from Bill') => Http::response(['QueryResponse' => ['Bill' => [
                    ['Id' => 'BILL-1', 'VendorRef' => ['value' => 'V-EXISTING'], 'TotalAmt' => 75, 'TxnDate' => '2026-01-11'],
                    ['Id' => 'BILL-2', 'VendorRef' => ['value' => 'V-NEW', 'name' => 'New Vendor'], 'TotalAmt' => 50],
                ]]]),
                str_contains($q, 'from Payment') => Http::response(['QueryResponse' => ['Payment' => [
                    ['Id' => 'PAY-1', 'TotalAmt' => 250, 'TxnDate' => '2026-01-12', 'Line' => [['LinkedTxn' => [['TxnId' => 'INV-1']]]]],
                    ['Id' => 'PAY-2', 'TotalAmt' => 10, 'Line' => []],
                    ['Id' => 'PAY-3', 'TotalAmt' => 10, 'Line' => [['LinkedTxn' => [['TxnId' => 'MISSING-INV']]]]],
                ]]]),
                str_contains($q, 'from Estimate') => Http::response(['QueryResponse' => ['Estimate' => [
                    ['Id' => 'EST-1', 'DocNumber' => 'E-1', 'CustomerRef' => ['value' => 'C-EXISTING'], 'TotalAmt' => 300],
                    ['DocNumber' => 'No ID'],
                ]]]),
                str_contains($q, 'from CreditMemo') => Http::response(['QueryResponse' => ['CreditMemo' => [
                    ['Id' => 'CM-1', 'DocNumber' => 'CM-1', 'CustomerRef' => ['value' => 'C-EXISTING'], 'TotalAmt' => 45],
                    ['DocNumber' => 'No ID'],
                ]]]),
                str_contains($q, 'from Purchase') => Http::response(['QueryResponse' => ['Purchase' => [
                    ['Id' => 'P1', 'TotalAmt' => 80, 'TxnDate' => '2026-01-10', 'AccountRef' => ['value' => '10'], 'PrivateNote' => 'Office supplies'],
                ]]]),
                str_contains($q, 'from Deposit') => Http::response(['QueryResponse' => ['Deposit' => [
                    ['Id' => 'D1', 'TotalAmt' => 500, 'TxnDate' => '2026-01-11', 'AccountRef' => ['value' => '10']],
                ]]]),
                str_contains($q, 'from Transfer') => Http::response(['QueryResponse' => ['Transfer' => [
                    ['Id' => 'T1', 'TransferAmt' => 150, 'TxnDate' => '2026-01-12'],
                ]]]),
                default => Http::response(['QueryResponse' => []]),
            };
        });

        $service = new QuickBooksService();
        $result = $service->sync($conn);

        expect($result)->toMatchArray([
            'customers' => 2,
            'vendors' => 2,
            'accounts' => 2,
            'invoices' => 2,
            'bills' => 2,
            'payments' => 3,
            'estimates' => 2,
            'credit_memos' => 2,
            'transactions' => 3,
        ]);

        expect((float) Transaction::where('external_id', 'qbo:Purchase:P1')->value('amount'))->toBe(-80.0);
        expect((float) Transaction::where('external_id', 'qbo:Deposit:D1')->value('amount'))->toBe(500.0);
    });
});

describe('XeroService', function (): void {
    beforeEach(function (): void {
        configureAccountingProviders();
    });

    it('builds authorization url, handles callback, and refreshes expired tokens', function (): void {
        Http::fake([
            'identity.xero.com/*' => Http::response([
                'access_token' => 'xero-access',
                'refresh_token' => 'xero-refresh',
                'expires_in' => 1800,
            ]),
            'api.xero.com/connections' => Http::response([
                ['tenantId' => 'tenant-abc'],
            ]),
            'api.xero.com/api.xro/2.0/Accounts' => Http::response(['Accounts' => []]),
        ]);

        $user = User::factory()->create();
        $service = new XeroService();

        expect($service->getAuthorizationUrl('xstate'))->toContain('client_id=xero-client');

        $conn = $service->handleCallback($user->id, 'xero-code');
        expect($conn)->toBeInstanceOf(XeroConnection::class)
            ->and($conn->tenant_id)->toBe('tenant-abc')
            ->and($conn->access_token)->toBe('xero-access');

        $conn->update(['token_expires_at' => now()->subMinute()]);
        expect($service->pullAccounts($conn->fresh()))->toBe(0);
    });

    it('pushes all Xero entities (create and update branches)', function (): void {
        Http::fake([
            '*/Invoices' => Http::response(['Invoices' => [['InvoiceID' => 'X-INV-1']]]),
            '*/Quotes' => Http::response(['Quotes' => [['QuoteID' => 'X-QT-1']]]),
            '*/CreditNotes' => Http::response(['CreditNotes' => [['CreditNoteID' => 'X-CN-1']]]),
            '*/Contacts' => Http::response(['Contacts' => [['ContactID' => 'X-CON-1']]]),
            '*/Accounts*' => Http::response(['Accounts' => [['AccountID' => 'X-ACC-1']]]),
            '*/Payments' => Http::response(['Payments' => [['PaymentID' => 'X-PAY-1']]]),
        ]);

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $conn = XeroConnection::create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'tenant_id' => 't-1',
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        $service = new XeroService();

        $customer = Customer::factory()->create(['team_id' => $team->id, 'xero_id' => null]);
        $vendor = Vendor::factory()->create(['team_id' => $team->id, 'xero_id' => null]);

        // Invoice & Bill with customer/vendor without xero_id first
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'xero_id' => null]);
        $service->pushInvoice($invoice, $conn);
        expect($invoice->fresh()->xero_id)->toBe('X-INV-1');

        $bill = Bill::factory()->create(['team_id' => $team->id, 'vendor_id' => $vendor->vendor_id, 'xero_id' => null]);
        $service->pushBill($bill, $conn);
        expect($bill->fresh()->xero_id)->toBe('X-INV-1');

        // Push customer & vendor so they now have xero_id
        $service->pushCustomer($customer, $conn);
        $service->pushVendor($vendor, $conn);

        // Update branches with xero_id set
        $service->pushInvoice($invoice->fresh(['customer']), $conn);
        $service->pushBill($bill->fresh(['vendor']), $conn);

        $account = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'expense', 'xero_id' => null]);
        $service->pushAccount($account, $conn); // PUT
        expect($account->fresh()->xero_id)->toBe('X-ACC-1');
        $service->pushAccount($account->fresh(), $conn); // POST

        $estimate = Estimate::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'xero_id' => null]);
        $service->pushEstimate($estimate, $conn);
        $service->pushEstimate($estimate->fresh(), $conn);

        $creditMemo = CreditMemo::create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'credit_memo_number' => 'CM-X1',
            'credit_memo_date' => '2026-01-10',
            'subtotal_amount' => 30,
            'total_amount' => 30,
            'status' => 'open',
        ]);
        $service->pushCreditMemo($creditMemo, $conn);
        $service->pushCreditMemo($creditMemo->fresh(), $conn);

        $payment = Payment::create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'payment_amount' => 50,
            'payment_date' => '2026-01-12',
        ]);
        $service->pushPayment($payment, $conn);
        expect($payment->fresh()->xero_id)->toBe('X-PAY-1');
    });

    it('syncs and pulls all Xero entities including edge cases', function (): void {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $conn = XeroConnection::create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'tenant_id' => 't-2',
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        Customer::factory()->create(['team_id' => $team->id, 'xero_id' => 'XC-EXISTING']);
        Vendor::factory()->create(['team_id' => $team->id, 'xero_id' => 'XV-EXISTING']);

        Http::fake(function ($request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/Contacts') => Http::response(['Contacts' => [
                    ['ContactID' => 'XC-1', 'Name' => 'Xero Cust', 'IsCustomer' => true, 'IsSupplier' => false],
                    ['ContactID' => 'XV-1', 'Name' => 'Xero Supp', 'IsCustomer' => false, 'IsSupplier' => true],
                    ['Name' => 'No ID', 'IsCustomer' => true, 'IsSupplier' => true],
                ]]),
                str_contains($url, '/Accounts') => Http::response(['Accounts' => [
                    ['AccountID' => 'XA-1', 'Name' => 'Sales', 'Class' => 'REVENUE', 'Code' => '200'],
                    ['AccountID' => 'XA-2', 'Name' => 'NonNum', 'Class' => 'ASSET', 'Code' => 'ABC'],
                ]]),
                str_contains($url, '/Invoices') && str_contains($url, 'ACCREC') => Http::response(['Invoices' => [
                    ['InvoiceID' => 'XINV-1', 'InvoiceNumber' => 'INV-X1', 'Contact' => ['ContactID' => 'XC-EXISTING'], 'Total' => 120, 'Date' => '2026-01-10'],
                    ['InvoiceID' => 'XINV-2', 'Contact' => ['Name' => 'New Xero Cust'], 'Total' => 80],
                ]]),
                str_contains($url, '/Invoices') && str_contains($url, 'ACCPAY') => Http::response(['Invoices' => [
                    ['InvoiceID' => 'XBILL-1', 'Contact' => ['ContactID' => 'XV-EXISTING'], 'Total' => 60, 'Date' => '2026-01-11'],
                    ['InvoiceID' => 'XBILL-2', 'Contact' => ['Name' => 'New Xero Vendor'], 'Total' => 40],
                ]]),
                str_contains($url, '/Payments') => Http::response(['Payments' => [
                    ['PaymentID' => 'XPAY-1', 'Invoice' => ['InvoiceID' => 'XINV-1'], 'Amount' => 120, 'Date' => '2026-01-12'],
                    ['PaymentID' => 'XPAY-2', 'Invoice' => ['InvoiceID' => ''], 'Amount' => 10],
                    ['PaymentID' => 'XPAY-3', 'Invoice' => ['InvoiceID' => 'UNKNOWN'], 'Amount' => 10],
                ]]),
                str_contains($url, '/Quotes') => Http::response(['Quotes' => [
                    ['QuoteID' => 'XQT-1', 'QuoteNumber' => 'Q-1', 'Contact' => ['ContactID' => 'XC-EXISTING'], 'Total' => 200],
                    ['QuoteNumber' => 'No ID'],
                ]]),
                str_contains($url, '/CreditNotes') => Http::response(['CreditNotes' => [
                    ['CreditNoteID' => 'XCN-1', 'CreditNoteNumber' => 'CN-1', 'Contact' => ['ContactID' => 'XC-EXISTING'], 'Total' => 50],
                    ['CreditNoteNumber' => 'No ID'],
                ]]),
                str_contains($url, '/BankTransactions') => Http::response(['BankTransactions' => [
                    ['BankTransactionID' => 'BT-1', 'Type' => 'SPEND', 'Total' => 45, 'Date' => '2026-01-10', 'Reference' => 'Spend ref', 'BankAccount' => ['AccountID' => 'XA-1']],
                    ['BankTransactionID' => 'BT-2', 'Type' => 'RECEIVE', 'Total' => 90, 'Date' => '2026-01-11'],
                ]]),
                default => Http::response([]),
            };
        });

        $result = (new XeroService())->sync($conn);

        expect($result['customers'])->toBe(2)
            ->and($result['vendors'])->toBe(2)
            ->and($result['accounts'])->toBe(2)
            ->and($result['invoices'])->toBe(2)
            ->and($result['bills'])->toBe(2)
            ->and($result['payments'])->toBe(3)
            ->and($result['estimates'])->toBe(2)
            ->and($result['credit_memos'])->toBe(2)
            ->and($result['transactions'])->toBe(2);

        expect((float) Transaction::where('external_id', 'xero:bank:BT-1')->value('amount'))->toBe(-45.0);
    });
});

describe('SageService', function (): void {
    beforeEach(function (): void {
        configureAccountingProviders();
    });

    it('builds authorization url, handles callback, and refreshes expired tokens', function (): void {
        Http::fake([
            'oauth.accounting.sage.com/*' => Http::response([
                'access_token' => 'sage-access',
                'refresh_token' => 'sage-refresh',
                'expires_in' => 3600,
            ]),
            'api.accounting.sage.com/v3.1/businesses' => Http::response([
                '$items' => [['id' => 'biz-1']],
            ]),
            'api.accounting.sage.com/v3.1/ledger_accounts' => Http::response(['$items' => []]),
        ]);

        $user = User::factory()->create();
        $service = new SageService();

        expect($service->getAuthorizationUrl('sstate'))->toContain('client_id=sage-client');

        $conn = $service->handleCallback($user->id, 'sage-code');
        expect($conn)->toBeInstanceOf(SageConnection::class)
            ->and($conn->business_id)->toBe('biz-1');

        $conn->update(['token_expires_at' => now()->subMinute()]);
        expect($service->pullAccounts($conn->fresh()))->toBe(0);
    });

    it('pushes all Sage entities and covers all account type mappings', function (): void {
        Http::fake([
            '*/sales_invoices' => Http::response(['id' => 'SG-INV-1']),
            '*/quotes' => Http::response(['id' => 'SG-QT-1']),
            '*/sales_credit_notes' => Http::response(['id' => 'SG-CN-1']),
            '*/contacts' => Http::response(['id' => 'SG-CON-1']),
            '*/ledger_accounts' => Http::response(['id' => 'SG-ACC-1']),
            '*/purchase_invoices' => Http::response(['id' => 'SG-BILL-1']),
            '*/contact_payments' => Http::response(['id' => 'SG-PAY-1']),
        ]);

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $conn = SageConnection::create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'business_id' => 'biz-1',
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        $service = new SageService();
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $vendor = Vendor::factory()->create(['team_id' => $team->id]);

        $service->pushCustomer($customer, $conn);
        $service->pushVendor($vendor, $conn);

        foreach (['asset', 'liability', 'equity', 'revenue', 'expense'] as $type) {
            $acc = Account::factory()->create(['team_id' => $team->id, 'account_type' => $type]);
            $service->pushAccount($acc, $conn);
        }

        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id]);
        $service->pushInvoice($invoice, $conn);
        expect($invoice->fresh()->sage_id)->toBe('SG-INV-1');

        $estimate = Estimate::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id]);
        $service->pushEstimate($estimate, $conn);

        $creditMemo = CreditMemo::create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'credit_memo_number' => 'CM-SG1',
            'credit_memo_date' => '2026-01-10',
            'subtotal_amount' => 25,
            'total_amount' => 25,
            'status' => 'open',
        ]);
        $service->pushCreditMemo($creditMemo, $conn);

        $bill = Bill::factory()->create(['team_id' => $team->id, 'vendor_id' => $vendor->vendor_id]);
        $service->pushBill($bill, $conn);

        $payment = Payment::create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'payment_amount' => 50,
            'payment_date' => '2026-01-12',
        ]);
        $service->pushPayment($payment, $conn);
        expect($payment->fresh()->sage_id)->toBe('SG-PAY-1');
    });

    it('syncs and pulls all Sage entities including edge cases and account type mappings', function (): void {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $conn = SageConnection::create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'business_id' => 'biz-2',
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'token_expires_at' => now()->addHour(),
            'status' => 'active',
        ]);

        Customer::factory()->create(['team_id' => $team->id, 'sage_id' => 'SC-EXISTING']);
        Vendor::factory()->create(['team_id' => $team->id, 'sage_id' => 'SV-EXISTING']);

        Http::fake(function ($request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'contact_type=customer') => Http::response(['$items' => [
                    ['id' => 'SC-1', 'name' => 'Sage Cust', 'email' => 'sc@sage.test'],
                    ['name' => 'No ID'],
                ]]),
                str_contains($url, 'contact_type=supplier') => Http::response(['$items' => [
                    ['id' => 'SV-1', 'name' => 'Sage Supp'],
                    ['name' => 'No ID'],
                ]]),
                str_contains($url, '/ledger_accounts') => Http::response(['$items' => [
                    ['id' => 'SA-1', 'name' => 'Liab', 'ledger_account_type' => ['name' => 'liability'], 'nominal_code' => '2000'],
                    ['id' => 'SA-2', 'name' => 'Eq', 'ledger_account_type' => 'equity', 'nominal_code' => '3000'],
                    ['id' => 'SA-3', 'name' => 'Rev', 'ledger_account_type' => 'revenue', 'nominal_code' => '4000'],
                    ['id' => 'SA-4', 'name' => 'Exp', 'ledger_account_type' => 'expense', 'nominal_code' => '5000'],
                    ['id' => 'SA-5', 'name' => 'Asset', 'ledger_account_type' => 'asset', 'nominal_code' => 'NON-NUM', 'is_active' => false],
                    ['name' => 'No ID'],
                ]]),
                str_contains($url, '/sales_invoices') => Http::response(['$items' => [
                    ['id' => 'SINV-1', 'displayed_as' => 'SI-1', 'contact_id' => 'SC-EXISTING', 'total_amount' => 100, 'date' => '2026-01-10'],
                    ['id' => 'SINV-2', 'contact_name' => 'Brand New Sage Cust', 'total_amount' => 50],
                ]]),
                str_contains($url, '/purchase_invoices') => Http::response(['$items' => [
                    ['id' => 'SBILL-1', 'contact_id' => 'SV-EXISTING', 'total_amount' => 70, 'date' => '2026-01-11'],
                    ['id' => 'SBILL-2', 'contact_name' => 'New Sage Supp', 'total_amount' => 30],
                    ['total_amount' => 10],
                ]]),
                str_contains($url, '/contact_payments') => Http::response(['$items' => [
                    ['id' => 'SPAY-1', 'invoice_id' => 'SINV-1', 'total_amount' => 100, 'date' => '2026-01-12'],
                    ['invoice_id' => 'SINV-1'],
                    ['id' => 'SPAY-2', 'invoice_id' => ''],
                    ['id' => 'SPAY-3', 'invoice_id' => 'UNKNOWN'],
                ]]),
                str_contains($url, '/quotes') => Http::response(['$items' => [
                    ['id' => 'SQT-1', 'reference' => 'Q-1', 'contact_id' => 'SC-EXISTING', 'total_amount' => 150],
                    ['reference' => 'No ID'],
                ]]),
                str_contains($url, '/sales_credit_notes') => Http::response(['$items' => [
                    ['id' => 'SCN-1', 'reference' => 'CN-1', 'contact_id' => 'SC-EXISTING', 'total_amount' => 40],
                    ['reference' => 'No ID'],
                ]]),
                str_contains($url, '/bank_transactions') => Http::response(['$items' => [
                    ['id' => 'SBT-1', 'transaction_type' => 'payment', 'amount' => 65, 'date' => '2026-01-10', 'bank_account_id' => 'SA-5'],
                    ['id' => 'SBT-2', 'transaction_type' => 'receive', 'total_amount' => 120, 'date' => '2026-01-11'],
                ]]),
                default => Http::response(['$items' => []]),
            };
        });

        $result = (new SageService())->sync($conn);

        expect($result['customers'])->toBe(2)
            ->and($result['vendors'])->toBe(2)
            ->and($result['accounts'])->toBe(6)
            ->and($result['invoices'])->toBe(2)
            ->and($result['bills'])->toBe(3)
            ->and($result['payments'])->toBe(4)
            ->and($result['estimates'])->toBe(2)
            ->and($result['credit_memos'])->toBe(2)
            ->and($result['transactions'])->toBe(2);

        expect((float) Transaction::where('external_id', 'sage:bank:SBT-1')->value('amount'))->toBe(-65.0);
    });
});
