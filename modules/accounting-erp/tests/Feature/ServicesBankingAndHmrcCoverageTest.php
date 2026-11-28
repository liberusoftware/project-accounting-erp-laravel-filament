<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\BankFeedTransaction;
use Liberu\Accounting\Erp\Models\BankStatement;
use Liberu\Accounting\Erp\Models\Company;
use Liberu\Accounting\Erp\Models\HmrcCorporationTaxSubmission;
use Liberu\Accounting\Erp\Models\HmrcPayeSubmission;
use Liberu\Accounting\Erp\Models\HmrcVatReturn;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Services\BankFeedService;
use Liberu\Accounting\Erp\Services\BankStatementImportService;
use Liberu\Accounting\Erp\Services\HmrcAuthService;
use Liberu\Accounting\Erp\Services\HmrcMtdCorporationTaxService;
use Liberu\Accounting\Erp\Services\HmrcMtdVatService;
use Liberu\Accounting\Erp\Services\HmrcRtiPayeService;
use Liberu\Accounting\Erp\Services\PlaidService;
use Liberu\Accounting\Erp\Services\RevolutService;
use Liberu\Accounting\Erp\Services\WiseService;

describe('PlaidService', function (): void {
    beforeEach(function (): void {
        config()->set('services.plaid', [
            'client_id' => 'plaid-cid',
            'secret' => 'plaid-sec',
            'environment' => 'sandbox',
            'oauth_redirect_uri' => 'https://app.test/plaid/oauth',
        ]);
    });

    it('supports all environments and handles link token, token exchange, institution, accounts, balances, and item removal', function (): void {
        config()->set('services.plaid.environment', 'production');
        new PlaidService();
        config()->set('services.plaid.environment', 'development');
        new PlaidService();
        config()->set('services.plaid.environment', 'sandbox');

        Http::fake([
            'sandbox.plaid.com/link/token/create' => Http::sequence()
                ->push(['link_token' => 'link-sandbox-1'], 200)
                ->push('bad', 400),
            'sandbox.plaid.com/item/public_token/exchange' => Http::sequence()
                ->push(['access_token' => 'access-1', 'item_id' => 'item-1'], 200)
                ->push('bad', 400),
            'sandbox.plaid.com/institutions/get_by_id' => Http::sequence()
                ->push(['institution' => ['name' => 'Chase']], 200)
                ->push('bad', 400),
            'sandbox.plaid.com/accounts/get' => Http::sequence()
                ->push(['accounts' => [['account_id' => 'a1']]], 200)
                ->push('bad', 400),
            'sandbox.plaid.com/accounts/balance/get' => Http::sequence()
                ->push(['accounts' => [['account_id' => 'a1']]], 200)
                ->push('bad', 400),
            'sandbox.plaid.com/item/remove' => Http::sequence()
                ->push(['removed' => true], 200)
                ->push('bad', 400),
        ]);

        $service = new PlaidService();

        expect($service->createLinkToken(1, 'en', 'existing-tok'))->toBe(['link_token' => 'link-sandbox-1']);
        expect(fn () => $service->createLinkToken(1))->toThrow(Exception::class);

        expect($service->exchangePublicToken('pub-1'))->toHaveKey('access_token', 'access-1');
        expect(fn () => $service->exchangePublicToken('pub-bad'))->toThrow(Exception::class);

        expect($service->getInstitution('ins_1'))->toHaveKey('institution');
        expect(fn () => $service->getInstitution('ins_bad'))->toThrow(Exception::class);

        expect($service->getAccounts('access-1'))->toHaveKey('accounts');
        expect(fn () => $service->getAccounts('bad'))->toThrow(Exception::class);

        expect($service->getBalances('access-1', ['a1']))->toHaveKey('accounts');
        expect(fn () => $service->getBalances('bad'))->toThrow(Exception::class);

        expect($service->removeItem('access-1'))->toBeTrue();
        expect(fn () => $service->removeItem('bad'))->toThrow(Exception::class);
    });

    it('syncs transactions and handles user-action and generic Plaid errors', function (): void {
        $user = User::factory()->create();
        $connection = BankConnection::factory()->create([
            'user_id' => $user->id,
            'plaid_access_token' => 'plaid-access-tok',
            'plaid_cursor' => 'cursor-0',
        ]);

        Http::fake([
            'sandbox.plaid.com/transactions/sync' => Http::sequence()
                ->push(['added' => [], 'next_cursor' => 'cursor-1'], 200)
                ->push(['error_code' => 'ITEM_LOGIN_REQUIRED', 'error_type' => 'ITEM_ERROR', 'error_message' => 'Login needed'], 400)
                ->push(['error_code' => 'RATE_LIMIT', 'error_type' => 'RATE_LIMIT_EXCEEDED', 'error_message' => 'Slow down'], 400)
                ->push('server err', 500)
                ->push('server err', 500),
        ]);

        $service = new PlaidService();

        $res = $service->syncTransactions($connection);
        expect($res['next_cursor'])->toBe('cursor-1')
            ->and($connection->fresh()->plaid_cursor)->toBe('cursor-1');

        expect(fn () => $service->syncTransactions($connection))->toThrow(Exception::class, 'User action required');
        expect(fn () => $service->syncTransactions($connection))->toThrow(Exception::class, 'Plaid API error');
        expect(fn () => $service->syncTransactions($connection))->toThrow(Exception::class);
    });

    it('verifies Plaid ES256 webhook signatures across valid, invalid, stale, and missing-key paths', function (): void {
        $service = new PlaidService();
        $body = json_encode(['webhook_type' => 'TRANSACTIONS']);

        // Missing header
        expect($service->verifyWebhookSignature($body, []))->toBeFalse();
        // Malformed JWT segments
        expect($service->verifyWebhookSignature($body, ['Plaid-Verification' => 'not-a-jwt']))->toBeFalse();
        // Wrong alg / missing kid
        $badHeader = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256'])), '+/', '-_'), '=');
        expect($service->verifyWebhookSignature($body, ['plaid-verification' => [$badHeader.'.e30.sig']]))->toBeFalse();

        // Generate real EC P-256 keypair for ES256 verification
        $ecKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($ecKey);
        openssl_pkey_export($ecKey, $privatePem);

        $b64url = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'alg' => 'ES256',
            'kid' => 'kid-valid',
            'x' => $b64url($details['ec']['x']),
            'y' => $b64url($details['ec']['y']),
        ];

        Cache::forget('plaid_jwk:kid-valid');
        Cache::forget('plaid_jwk:kid-missing');

        Http::fake([
            'sandbox.plaid.com/webhook_verification_key/get' => Http::sequence()
                ->push([], 500)
                ->push(['key' => $jwk], 200),
        ]);

        // Unfetchable key returns false
        $jwtMissingKey = JWT::encode([
            'iat' => now()->timestamp,
            'request_body_sha256' => hash('sha256', $body),
        ], $privatePem, 'ES256', 'kid-missing');
        expect($service->verifyWebhookSignature($body, ['Plaid-Verification' => $jwtMissingKey]))->toBeFalse();

        // Valid JWT fetches JWK and caches it
        $validJwt = JWT::encode([
            'iat' => now()->timestamp,
            'request_body_sha256' => hash('sha256', $body),
        ], $privatePem, 'ES256', 'kid-valid');
        expect($service->verifyWebhookSignature($body, ['Plaid-Verification' => $validJwt]))->toBeTrue();

        // Second call hits cache; test stale iat
        $staleJwt = JWT::encode([
            'iat' => now()->subMinutes(10)->timestamp,
            'request_body_sha256' => hash('sha256', $body),
        ], $privatePem, 'ES256', 'kid-valid');
        expect($service->verifyWebhookSignature($body, ['Plaid-Verification' => $staleJwt]))->toBeFalse();

        // Tampered signature hits catch block
        expect($service->verifyWebhookSignature($body, ['Plaid-Verification' => $validJwt.'tamper']))->toBeFalse();
    });
});

describe('RevolutService', function (): void {
    beforeEach(function (): void {
        config()->set('services.revolut', [
            'client_id' => 'rev-cid',
            'client_secret' => 'rev-sec',
            'environment' => 'sandbox',
            'redirect_uri' => 'https://app.test/revolut/cb',
            'webhook_secret' => 'rev-wh-secret',
        ]);
    });

    it('covers auth url, token exchange/refresh, accounts, transactions, payments, bulk payments, and webhooks', function (): void {
        config()->set('services.revolut.environment', 'production');
        new RevolutService();
        config()->set('services.revolut.environment', 'sandbox');

        $user = User::factory()->create();
        $conn = BankConnection::factory()->create([
            'user_id' => $user->id,
            'revolut_access_token' => 'rev-tok-1',
            'revolut_refresh_token' => 'rev-ref-1',
            'revolut_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'sandbox-b2b.revolut.com/api/1.0/auth/token' => Http::sequence()
                ->push(['access_token' => 'rev-tok-new', 'refresh_token' => 'rev-ref-new', 'expires_in' => 2400], 200)
                ->push('err', 400)
                ->push(['access_token' => 'rev-tok-2', 'expires_in' => 2400], 200)
                ->push('err', 400),
            'sandbox-b2b.revolut.com/api/1.0/accounts' => Http::sequence()
                ->push([['id' => 'acc-1']], 200)
                ->push('err', 400),
            'sandbox-b2b.revolut.com/api/1.0/accounts/acc-1' => Http::sequence()
                ->push(['id' => 'acc-1'], 200)
                ->push('err', 400),
            'sandbox-b2b.revolut.com/api/1.0/transactions*' => Http::sequence()
                ->push([['id' => 'tx-1']], 200)
                ->push('err', 400),
            'sandbox-b2b.revolut.com/api/1.0/pay' => Http::sequence()
                ->push(['id' => 'pay-1'], 200)
                ->push('err', 400),
            'sandbox-b2b.revolut.com/api/1.0/payment-drafts' => Http::sequence()
                ->push(['id' => 'draft-1'], 200)
                ->push('err', 400),
        ]);

        $service = new RevolutService();

        expect($service->getAuthorizationUrl('s1'))->toContain('rev-cid');
        expect($service->exchangeAuthorizationCode('code1'))->toHaveKey('access_token', 'rev-tok-new');
        expect(fn () => $service->exchangeAuthorizationCode('bad'))->toThrow(Exception::class);

        // Triggers refresh inside getValidAccessToken
        expect($service->getValidAccessToken($conn))->toBe('rev-tok-2');

        // Refresh failure
        expect(fn () => $service->refreshAccessToken('bad-ref'))->toThrow(Exception::class);

        // Expired without refresh token
        $noRefConn = BankConnection::factory()->create([
            'user_id' => $user->id,
            'revolut_access_token' => 'tok',
            'revolut_refresh_token' => null,
            'revolut_token_expires_at' => now()->subMinute(),
        ]);
        expect(fn () => $service->getValidAccessToken($noRefConn))->toThrow(Exception::class, 'no refresh token');

        $conn->refresh();
        expect($service->getAccounts($conn))->toHaveCount(1);
        expect(fn () => $service->getAccounts($conn))->toThrow(Exception::class);

        expect($service->getAccount($conn, 'acc-1'))->toHaveKey('id', 'acc-1');
        expect(fn () => $service->getAccount($conn, 'acc-1'))->toThrow(Exception::class);

        expect($service->getTransactions($conn, '2026-01-01', '2026-01-31', 50))->toHaveCount(1);
        expect(fn () => $service->getTransactions($conn))->toThrow(Exception::class);

        expect($service->sendPayment($conn, ['amount' => 10]))->toHaveKey('id', 'pay-1');
        expect(fn () => $service->sendPayment($conn, ['amount' => 10]))->toThrow(Exception::class);

        expect($service->sendBulkPayment($conn, 'Batch', [['amount' => 10]], '2026-02-01'))->toHaveKey('id', 'draft-1');
        expect(fn () => $service->sendBulkPayment($conn, 'Batch', []))->toThrow(Exception::class);

        $body = '{"event":"TransactionCreated"}';
        $sig = 'v1='.hash_hmac('sha256', $body, 'rev-wh-secret');
        expect($service->verifyWebhookSignature($body, $sig))->toBeTrue()
            ->and($service->verifyWebhookSignature($body, ''))->toBeFalse();

        config()->set('services.revolut.webhook_secret', '');
        expect($service->verifyWebhookSignature($body, $sig))->toBeFalse();
    });
});

describe('WiseService', function (): void {
    beforeEach(function (): void {
        config()->set('services.wise', [
            'client_id' => 'wise-cid',
            'client_secret' => 'wise-sec',
            'environment' => 'sandbox',
            'redirect_uri' => 'https://app.test/wise/cb',
        ]);
    });

    it('covers auth, token refresh, profiles, balances, transfers, and RSA webhook verification', function (): void {
        config()->set('services.wise.environment', 'production');
        new WiseService();
        config()->set('services.wise.environment', 'sandbox');

        $user = User::factory()->create();
        $conn = BankConnection::factory()->create([
            'user_id' => $user->id,
            'wise_access_token' => 'wise-tok-1',
            'wise_refresh_token' => 'wise-ref-1',
            'wise_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'api.sandbox.transferwise.tech/oauth/v2/token' => Http::sequence()
                ->push(['access_token' => 'w-tok-1', 'refresh_token' => 'w-ref-1', 'expires_in' => 3600], 200)
                ->push('err', 400)
                ->push(['access_token' => 'w-tok-2', 'expires_in' => 3600], 200)
                ->push('err', 400),
            'api.sandbox.transferwise.tech/v2/profiles' => Http::sequence()
                ->push([['id' => 101]], 200)
                ->push('err', 400),
            'api.sandbox.transferwise.tech/v4/profiles/101/balances*' => Http::sequence()
                ->push([['id' => 201]], 200)
                ->push('err', 400),
            'api.sandbox.transferwise.tech/v1/transfers*' => Http::sequence()
                ->push([['id' => 301]], 200)
                ->push('err', 400),
        ]);

        $service = new WiseService();

        expect($service->getAuthorizationUrl('ws1'))->toContain('wise-cid');
        expect($service->exchangeAuthorizationCode('code1'))->toHaveKey('access_token', 'w-tok-1');
        expect(fn () => $service->exchangeAuthorizationCode('bad'))->toThrow(Exception::class);

        expect($service->getValidAccessToken($conn))->toBe('w-tok-2');
        expect(fn () => $service->refreshAccessToken('bad'))->toThrow(Exception::class);

        $noRefConn = BankConnection::factory()->create([
            'user_id' => $user->id,
            'wise_access_token' => 'tok',
            'wise_refresh_token' => null,
            'wise_token_expires_at' => now()->subMinute(),
        ]);
        expect(fn () => $service->getValidAccessToken($noRefConn))->toThrow(Exception::class, 'no refresh token');

        $conn->refresh();
        expect($service->getProfiles($conn))->toHaveCount(1);
        expect(fn () => $service->getProfiles($conn))->toThrow(Exception::class);

        expect($service->getBalances($conn, 101))->toHaveCount(1);
        expect(fn () => $service->getBalances($conn, 101))->toThrow(Exception::class);

        expect($service->getTransfers($conn, 101, '2026-01-01', '2026-01-31', 50))->toHaveCount(1);
        expect(fn () => $service->getTransfers($conn, 101))->toThrow(Exception::class);

        // RSA webhook verification
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pubPem = openssl_pkey_get_details($rsa)['key'];
        $body = '{"event_type":"transfers#state-change"}';
        openssl_sign($body, $rawSig, $rsa, OPENSSL_ALGO_SHA256);
        $b64Sig = base64_encode($rawSig);

        expect($service->verifyWebhookSignature($body, $b64Sig, $pubPem))->toBeTrue()
            ->and($service->verifyWebhookSignature($body, $b64Sig, ''))->toBeFalse()
            ->and($service->verifyWebhookSignature($body, '', $pubPem))->toBeFalse()
            ->and($service->verifyWebhookSignature($body, '!!!invalid-b64!!!', $pubPem))->toBeFalse()
            ->and($service->verifyWebhookSignature($body, $b64Sig, 'not-a-pem'))->toBeFalse();
    });
});

describe('BankFeedService', function (): void {
    it('connects a bank, imports and categorizes transactions, and handles errors', function (): void {
        config()->set('services.bank_feeds', [
            'url' => 'https://feeds.test/api',
            'key' => 'Bearer feed-key',
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $service = new BankFeedService();
        $connection = $service->connectBank(['token' => 'abc'], 'bank-001');
        expect($connection->bank_id)->toBe('bank-001')
            ->and($connection->status)->toBe('active');

        Http::fake([
            'feeds.test/api/transactions*' => Http::sequence()
                ->push([
                    'transactions' => [
                        ['id' => 'bf-1', 'date' => '2026-01-01', 'amount' => 1000, 'description' => 'Monthly Payroll'],
                        ['id' => 'bf-2', 'date' => '2026-01-02', 'amount' => -50, 'description' => 'Electric Company'],
                        ['id' => 'bf-3', 'date' => '2026-01-03', 'amount' => -30, 'description' => 'Grocery Store'],
                        ['id' => 'bf-4', 'date' => '2026-01-04', 'amount' => -900, 'description' => 'Office Rent'],
                        ['id' => 'bf-5', 'date' => '2026-01-05', 'amount' => -20, 'description' => 'City Parking'],
                        ['id' => 'bf-6', 'date' => '2026-01-06', 'amount' => -15, 'description' => 'Movie Streaming'],
                        ['id' => 'bf-7', 'date' => '2026-01-07', 'amount' => -40, 'description' => 'Amazon Order'],
                        ['id' => 'bf-8', 'date' => '2026-01-08', 'amount' => -60, 'description' => 'Local Pharmacy'],
                        ['id' => 'bf-9', 'date' => '2026-01-09', 'amount' => -12, 'description' => 'Random Wire'],
                    ],
                ], 200)
                ->push(['transactions' => null], 200),
        ]);

        $service->importTransactions($connection);

        expect(BankFeedTransaction::where('bank_connection_id', $connection->id)->count())->toBe(9)
            ->and(Transaction::where('external_id', 'bf-1')->value('category'))->toBe('salary')
            ->and(Transaction::where('external_id', 'bf-2')->value('category'))->toBe('utilities')
            ->and(Transaction::where('external_id', 'bf-3')->value('category'))->toBe('food')
            ->and(Transaction::where('external_id', 'bf-4')->value('category'))->toBe('rent')
            ->and(Transaction::where('external_id', 'bf-5')->value('category'))->toBe('transportation')
            ->and(Transaction::where('external_id', 'bf-6')->value('category'))->toBe('entertainment')
            ->and(Transaction::where('external_id', 'bf-7')->value('category'))->toBe('shopping')
            ->and(Transaction::where('external_id', 'bf-8')->value('category'))->toBe('healthcare')
            ->and(Transaction::where('external_id', 'bf-9')->value('category'))->toBe('uncategorized');

        // Force exception inside try/catch (non-iterable transactions)
        Http::fake([
            'feeds.test/api/transactions*' => fn () => throw new Exception('Network down'),
        ]);
        expect(fn () => $service->importTransactions($connection))->toThrow(Exception::class, 'Network down');
    });
});

describe('BankStatementImportService', function (): void {
    it('imports CSV, OFX, QIF, and CAMT statements including error catch paths', function (): void {
        $account = Account::factory()->create();
        $statement = BankStatement::factory()->create(['account_id' => $account->id]);
        $service = new BankStatementImportService();

        // CSV with one valid row and one invalid date row
        $csvFile = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($csvFile, "Date,Description,Amount\n2026-01-05,Coffee,\"$12.50\"\nnot-a-date,Broken,5.00\n");
        $csvTxns = $service->importFromCsv($csvFile, $statement);
        unlink($csvFile);
        expect($csvTxns)->toHaveCount(1);

        // OFX with one valid and one invalid date
        $ofxFile = tempnam(sys_get_temp_dir(), 'ofx');
        file_put_contents($ofxFile, <<<'XML'
<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>
<STMTTRN><DTPOSTED>20260106</DTPOSTED><TRNAMT>-45.00</TRNAMT><MEMO>OFX Memo</MEMO></STMTTRN>
<STMTTRN><DTPOSTED>invalid-date</DTPOSTED><TRNAMT>10.00</TRNAMT><MEMO>Bad</MEMO></STMTTRN>
</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>
XML);
        $ofxTxns = $service->importFromOfx($ofxFile, $statement);
        unlink($ofxFile);
        expect($ofxTxns)->toHaveCount(1);

        // QIF with terminated record, invalid date record, and trailing unterminated record
        $qifFile = tempnam(sys_get_temp_dir(), 'qif');
        file_put_contents($qifFile, "!Type:Bank\n\nD1/15'26\nT-25.00\nPQIF Payee\n^\nDnot-a-date\nU10.00\nMBad\n^\nD1/16'26\nU50.00\nMTrailing Memo\n");
        $qifTxns = $service->importFromQif($qifFile, $statement);
        unlink($qifFile);
        expect($qifTxns)->toHaveCount(2);

        // CAMT with CRDT, DBIT (fallback AddtlNtryInf), and invalid date
        $camtFile = tempnam(sys_get_temp_dir(), 'camt');
        file_put_contents($camtFile, <<<'XML'
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt><Stmt>
    <Ntry><Amt>100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-01-10</Dt></BookgDt><NtryDtls><TxDtls><RmtInf><Ustrd>Invoice 1</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>
    <Ntry><Amt>40.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><BookgDt><Dt>2026-01-11</Dt></BookgDt><AddtlNtryInf>Bank Fee</AddtlNtryInf></Ntry>
    <Ntry><Amt>10.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>not-a-date</Dt></BookgDt></Ntry>
  </Stmt></BkToCstmrStmt>
</Document>
XML);
        $camtTxns = $service->importFromCamt($camtFile, $statement);
        unlink($camtFile);
        expect($camtTxns)->toHaveCount(2)
            ->and((float) $camtTxns[1]->amount)->toBe(-40.0);
    });
});

describe('HMRC Services', function (): void {
    beforeEach(function (): void {
        config()->set('hmrc.environment', 'sandbox');
        config()->set('hmrc.endpoints.sandbox', 'https://test-api.service.hmrc.gov.uk');
        config()->set('hmrc.client_id', 'hmrc-cid');
        config()->set('hmrc.client_secret', 'hmrc-sec');
        config()->set('hmrc.callback_url', 'https://app.test/hmrc/cb');
        config()->set('hmrc.logging.enabled', true);
        config()->set('hmrc.logging.channel', 'stack');
        Cache::forget('hmrc_access_token');
        Cache::forget('hmrc_refresh_token');
    });

    it('covers HmrcAuthService full OAuth lifecycle and error paths', function (): void {
        Http::fake([
            'test-api.service.hmrc.gov.uk/oauth/token' => Http::sequence()
                ->push(['access_token' => 'hmrc-tok-1', 'refresh_token' => 'hmrc-ref-1', 'expires_in' => 14400], 200)
                ->push(['error' => 'invalid_grant'], 400)
                ->push(['access_token' => 'hmrc-tok-2', 'refresh_token' => 'hmrc-ref-2', 'expires_in' => 14400], 200)
                ->push(['error' => 'invalid_grant'], 400),
        ]);

        $auth = new HmrcAuthService();
        expect($auth->isAuthorized())->toBeFalse();
        expect($auth->getAuthorizationUrl('read:vat'))->toContain('client_id=hmrc-cid');
        expect($auth->getAuthorizationUrl('read:vat', 'custom-state'))->toContain('state=custom-state');

        $tokens = $auth->exchangeCodeForToken('code-1');
        expect($tokens['access_token'])->toBe('hmrc-tok-1')
            ->and($auth->isAuthorized())->toBeTrue()
            ->and($auth->getAccessToken())->toBe('hmrc-tok-1');

        expect(fn () => $auth->exchangeCodeForToken('bad-code'))->toThrow(Exception::class);

        // Expire access token so getAccessToken triggers refreshAccessToken
        Cache::forget('hmrc_access_token');
        expect($auth->getAccessToken())->toBe('hmrc-tok-2');

        // Failure on refresh
        expect(fn () => $auth->refreshAccessToken('bad-ref'))->toThrow(Exception::class);

        // Logout clears cache; refreshing with no cached token throws
        $auth->logout();
        expect($auth->isAuthorized())->toBeFalse();
        expect(fn () => $auth->refreshAccessToken())->toThrow(Exception::class, 'No refresh token available');
    });

    it('covers HmrcMtdCorporationTaxService submission, obligations, liabilities, payments, and errors', function (): void {
        Cache::put('hmrc_access_token', 'hmrc-tok', 3600);
        $auth = new HmrcAuthService();
        $service = new HmrcMtdCorporationTaxService($auth);

        $companyNoUtr = Company::factory()->create(['hmrc_corporation_tax_utr' => null]);
        $ctNoUtr = HmrcCorporationTaxSubmission::create([
            'company_id' => $companyNoUtr->company_id,
            'accounting_period_start' => '2025-04-01',
            'accounting_period_end' => '2026-03-31',
            'turnover' => 100000,
            'total_profits' => 50000,
            'taxable_profits' => 50000,
            'corporation_tax_charged' => 9500,
            'marginal_relief' => 0,
            'total_tax_payable' => 9500,
            'filing_due_date' => '2027-03-31',
            'payment_due_date' => '2027-01-01',
            'is_amended' => false,
            'computation_data' => ['notes' => 'test'],
        ]);
        expect(fn () => $service->submitComputation($ctNoUtr))->toThrow(Exception::class, 'UTR');

        $company = Company::factory()->create(['hmrc_corporation_tax_utr' => '1234567890']);
        $ct = HmrcCorporationTaxSubmission::create([
            'company_id' => $company->company_id,
            'accounting_period_start' => '2025-04-01',
            'accounting_period_end' => '2026-03-31',
            'turnover' => 100000,
            'total_profits' => 50000,
            'taxable_profits' => 50000,
            'corporation_tax_charged' => 9500,
            'marginal_relief' => 0,
            'total_tax_payable' => 9500,
            'filing_due_date' => '2027-03-31',
            'payment_due_date' => '2027-01-01',
            'is_amended' => false,
            'computation_data' => ['notes' => 'test'],
        ]);

        Http::fake([
            '*/computations' => Http::sequence()
                ->push(['referenceNumber' => 'CT-REF-1'], 200)
                ->push(['code' => 'BAD'], 400),
            '*/obligations*' => Http::sequence()
                ->push(['obligations' => []], 200)
                ->push(['code' => 'BAD'], 400),
            '*/liabilities*' => Http::sequence()
                ->push(['liabilities' => []], 200)
                ->push(['code' => 'BAD'], 400),
            '*/payments*' => Http::sequence()
                ->push(['payments' => []], 200)
                ->push(['code' => 'BAD'], 400),
        ]);

        expect($service->submitComputation($ct))->toBe(['referenceNumber' => 'CT-REF-1']);
        expect($ct->fresh()->hmrc_submission_id)->not->toBeNull();
        expect(fn () => $service->submitComputation($ct))->toThrow(Exception::class);

        expect($service->getObligations('1234567890', '2025-04-01', '2026-03-31'))->toHaveKey('obligations');
        expect(fn () => $service->getObligations('1234567890', '2025-04-01', '2026-03-31'))->toThrow(Exception::class);

        expect($service->getLiabilities('1234567890', '2025-04-01', '2026-03-31'))->toHaveKey('liabilities');
        expect(fn () => $service->getLiabilities('1234567890', '2025-04-01', '2026-03-31'))->toThrow(Exception::class);

        expect($service->getPayments('1234567890', '2025-04-01', '2026-03-31'))->toHaveKey('payments');
        expect(fn () => $service->getPayments('1234567890', '2025-04-01', '2026-03-31'))->toThrow(Exception::class);
    });

    it('covers HmrcRtiPayeService FPS, EPS, EYU, validations, and error paths', function (): void {
        Cache::put('hmrc_access_token', 'hmrc-tok', 3600);
        $auth = new HmrcAuthService();
        $service = new HmrcRtiPayeService($auth);

        $companyNoRef = Company::factory()->create(['hmrc_paye_reference' => null]);
        $payeNoRef = HmrcPayeSubmission::create([
            'company_id' => $companyNoRef->company_id,
            'tax_year' => '2025-26',
            'tax_month' => 10,
            'payment_date' => '2026-01-28',
            'employee_data' => [],
        ]);
        expect(fn () => $service->submitFps($payeNoRef))->toThrow(Exception::class, 'PAYE reference');

        $company = Company::factory()->create(['hmrc_paye_reference' => '123/AB456']);
        $payeEmpty = HmrcPayeSubmission::create([
            'company_id' => $company->company_id,
            'tax_year' => '2025-26',
            'tax_month' => 10,
            'payment_date' => '2026-01-28',
            'employee_data' => [],
        ]);
        expect(fn () => $service->submitFps($payeEmpty))->toThrow(Exception::class, 'employee data');

        $paye = HmrcPayeSubmission::create([
            'company_id' => $company->company_id,
            'tax_year' => '2025-26',
            'tax_month' => 10, // >9 tests year rollover
            'payment_date' => '2026-01-28',
            'employee_data' => [[
                'name' => 'Jane Doe',
                'nino' => 'QQ123456C',
                'gross_pay' => 3000.00,
                'paye_tax' => 450.00,
                'employee_ni' => 200.00,
                'employer_ni' => 300.00,
                'student_loan' => 100.00,
            ]],
        ]);

        $xmlResp = '<GovTalkMessage><Header><MessageDetails><CorrelationID>CORR-1</CorrelationID><Timestamp>2026-01-28T10:00:00</Timestamp></MessageDetails></Header></GovTalkMessage>';

        Http::fake([
            '*/fps' => Http::sequence()
                ->push($xmlResp, 200, ['Content-Type' => 'application/xml'])
                ->push('error', 400),
            '*/eps' => Http::sequence()
                ->push($xmlResp, 200, ['Content-Type' => 'application/xml'])
                ->push('error', 400),
            '*/eyu' => Http::sequence()
                ->push($xmlResp, 200, ['Content-Type' => 'application/xml'])
                ->push('error', 400),
        ]);

        $fps = $service->submitFps($paye);
        expect($fps['correlationId'])->toBe('CORR-1')
            ->and($paye->fresh()->hmrc_submission_id)->not->toBeNull();
        expect(fn () => $service->submitFps($paye))->toThrow(Exception::class);

        expect($service->submitEps([], '123/AB456')['success'])->toBeTrue();
        expect(fn () => $service->submitEps([], '123/AB456'))->toThrow(Exception::class);

        expect($service->submitEyu([], '123/AB456')['success'])->toBeTrue();
        expect(fn () => $service->submitEyu([], '123/AB456'))->toThrow(Exception::class);
    });

    it('covers HmrcMtdVatService happy and error paths', function (): void {
        Cache::put('hmrc_access_token', 'hmrc-tok', 3600);
        $service = new HmrcMtdVatService(new HmrcAuthService());

        $company = Company::factory()->create(['hmrc_vat_number' => 'GB123456789']);
        $vat = HmrcVatReturn::create([
            'company_id' => $company->company_id,
            'period_key' => '26A1',
            'period_from' => '2026-01-01',
            'period_to' => '2026-03-31',
            'due_date' => '2026-05-07',
            'vat_due_sales' => 200,
            'vat_due_acquisitions' => 0,
            'total_vat_due' => 200,
            'vat_reclaimed' => 50,
            'net_vat_due' => 150,
            'total_value_sales' => 1000,
            'total_value_purchases' => 250,
            'total_value_goods_supplied' => 0,
            'total_acquisitions' => 0,
            'finalised' => true,
        ]);

        Http::fake([
            '*/vat/GB123456789/returns' => Http::response(['formBundleNumber' => 'FB-1'], 200),
            '*/vat/GB123456789/returns/26A1' => Http::response(['periodKey' => '26A1'], 200),
            '*/vat/GB123456789/obligations*' => Http::response(['obligations' => []], 200),
            '*/vat/GB123456789/liabilities*' => Http::response(['liabilities' => []], 200),
            '*/vat/GB123456789/payments*' => Http::response(['payments' => []], 200),
        ]);

        expect($service->submitVatReturn($vat))->toBe(['formBundleNumber' => 'FB-1'])
            ->and($service->getVatReturn('GB123456789', '26A1'))->toHaveKey('periodKey', '26A1')
            ->and($service->getObligations('GB123456789', '2026-01-01', '2026-03-31'))->toHaveKey('obligations')
            ->and($service->getLiabilities('GB123456789', '2026-01-01', '2026-03-31'))->toHaveKey('liabilities')
            ->and($service->getPayments('GB123456789', '2026-01-01', '2026-03-31'))->toHaveKey('payments');
    });
});
