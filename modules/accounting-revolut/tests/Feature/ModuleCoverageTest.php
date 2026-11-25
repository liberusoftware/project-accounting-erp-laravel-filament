<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Liberu\Accounting\BankFeeds\Models\BankFeedConnection;
use Liberu\Accounting\BankFeeds\Models\BankFeedInstitution;
use Liberu\Accounting\Revolut\RevolutAdapter;
use Liberu\Accounting\Revolut\RevolutServiceProvider;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;
use Liberu\Payment\Contracts\DTOs\ConnectionHealthDto;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\PaymentCapabilityDescriptorDto;

function makeRevolutBankFeedConnection(?string $cursor = null): BankFeedConnection
{
    $institution = BankFeedInstitution::create([
        'provider' => 'revolut',
        'external_id' => 'revolut-inst-'.uniqid(),
        'name' => 'Revolut Business',
        'country' => 'GB',
    ]);

    return BankFeedConnection::create([
        'team_id' => 10,
        'institution_id' => $institution->id,
        'provider' => 'revolut',
        'name' => 'Main Revolut',
        'external_reference' => 'rev-conn-'.uniqid(),
        'access_token' => 'rev-access-token',
        'cursor' => $cursor,
        'status' => 'active',
    ]);
}

it('registers the revolut service provider and adapter in integration and payment registries', function (): void {
    expect(app()->getProvider(RevolutServiceProvider::class))
        ->toBeInstanceOf(RevolutServiceProvider::class);

    $integration = app(IntegrationRegistry::class)->get('revolut');
    expect($integration)->toBeInstanceOf(RevolutAdapter::class);

    $gatewayRegistry = app(PaymentGatewayRegistryContract::class);
    expect($gatewayRegistry->has('revolut'))->toBeTrue()
        ->and($gatewayRegistry->resolve('revolut'))->toBeInstanceOf(RevolutAdapter::class);
});

it('exposes metadata, capability descriptor, credential check, oauth urls, and webhook verification', function (): void {
    config()->set('services.revolut.client_id', 'rev-client-123');
    config()->set('services.revolut.client_secret', 'rev-secret-456');
    config()->set('services.revolut.redirect_uri', 'https://app.example.test/oauth/revolut/callback');
    config()->set('services.revolut.webhook_secret', 'whsec_test_secret');
    config()->set('services.revolut.environment', 'sandbox');

    $adapter = new RevolutAdapter();

    expect($adapter->name())->toBe('revolut')
        ->and($adapter->providerKey())->toBe('revolut')
        ->and($adapter->capabilities())->toBe(['bank-feeds', 'accounts', 'balances', 'transactions', 'payments', 'bulk-payments']);

    $descriptor = $adapter->descriptor();
    expect($descriptor)->toBeInstanceOf(PaymentCapabilityDescriptorDto::class)
        ->and($descriptor->providerKey)->toBe('revolut')
        ->and($descriptor->displayName)->toBe('Revolut')
        ->and($descriptor->supportedMethods)->toBe(['bank_transfer'])
        ->and($descriptor->supportedCurrencies)->toBe([])
        ->and($descriptor->supportsAuthorizeOnly)->toBeFalse()
        ->and($descriptor->supportsPartialCapture)->toBeFalse()
        ->and($descriptor->supportsPartialRefund)->toBeFalse()
        ->and($descriptor->supportsVaulting)->toBeFalse()
        ->and($descriptor->supportsDisputes)->toBeFalse()
        ->and($descriptor->supportsSettlementReconciliation)->toBeFalse();

    expect($adapter->test(['client_id' => 'id', 'client_secret' => 'sec']))->toBeTrue()
        ->and($adapter->test(['client_id' => 'id']))->toBeFalse()
        ->and($adapter->test(['client_secret' => 'sec']))->toBeFalse()
        ->and($adapter->test([]))->toBeFalse();

    $sandboxAuthUrl = $adapter->authorizationUrl('state-abc');
    expect($sandboxAuthUrl)->toStartWith('https://sandbox-business.revolut.com/app-confirm?')
        ->and($sandboxAuthUrl)->toContain('client_id=rev-client-123')
        ->and($sandboxAuthUrl)->toContain('state=state-abc')
        ->and($sandboxAuthUrl)->toContain('response_type=code');

    config()->set('services.revolut.environment', 'production');
    expect($adapter->authorizationUrl('state-prod'))
        ->toStartWith('https://business.revolut.com/app-confirm?');

    $body = '{"event":"TransactionCreated","data":{"id":"tx-1"}}';
    $validSig = 'v1='.hash_hmac('sha256', $body, 'whsec_test_secret');
    expect($adapter->verifyWebhookSignature($body, $validSig))->toBeTrue()
        ->and($adapter->verifyWebhookSignature($body, 'v1=wrong'))->toBeFalse()
        ->and($adapter->verifyWebhookSignature($body, ''))->toBeFalse();

    config()->set('services.revolut.webhook_secret', '');
    expect($adapter->verifyWebhookSignature($body, $validSig))->toBeFalse();
});

it('exchanges authorization codes and refreshes access tokens via http', function (): void {
    config()->set('services.revolut.client_id', 'cid');
    config()->set('services.revolut.client_secret', 'csec');
    config()->set('services.revolut.redirect_uri', 'https://app.example.test/cb');
    config()->set('services.revolut.environment', 'sandbox');

    Http::fake([
        'https://sandbox-b2b.revolut.com/api/1.0/auth/token' => Http::sequence()
            ->push(['access_token' => 'tok-1', 'refresh_token' => 'ref-1'], 200)
            ->push(['access_token' => 'tok-2', 'refresh_token' => 'ref-2'], 200)
            ->push(['error' => 'invalid_grant'], 400),
    ]);

    $adapter = new RevolutAdapter();

    $exchanged = $adapter->exchangeAuthorizationCode('auth-code-9');
    expect($exchanged)->toBe(['access_token' => 'tok-1', 'refresh_token' => 'ref-1']);

    $refreshed = $adapter->refreshAccessToken('ref-1');
    expect($refreshed)->toBe(['access_token' => 'tok-2', 'refresh_token' => 'ref-2']);

    expect(fn () => $adapter->refreshAccessToken('bad-ref'))->toThrow(RequestException::class);

    Http::assertSent(fn (Request $req): bool => $req['grant_type'] === 'authorization_code' && $req['code'] === 'auth-code-9');
    Http::assertSent(fn (Request $req): bool => $req['grant_type'] === 'refresh_token' && $req['refresh_token'] === 'ref-1');
});

it('tests merchant account connection health across success, failure, missing token, and connection exceptions', function (): void {
    $adapter = new RevolutAdapter();

    $missingToken = new MerchantAccountContextDto('10', 'revolut', ['access_token' => '   '], true);
    $missingHealth = $adapter->testConnection($missingToken);
    expect($missingHealth)->toBeInstanceOf(ConnectionHealthDto::class)
        ->and($missingHealth->providerKey)->toBe('revolut')
        ->and($missingHealth->healthy)->toBeFalse()
        ->and($missingHealth->message)->toBe('An access token is required.');

    Http::fake([
        'https://sandbox-b2b.revolut.com/api/1.0/accounts' => Http::sequence()
            ->push([['id' => 'acc-1']], 200)
            ->push(['message' => 'Unauthorized'], 401),
        'https://b2b.revolut.com/api/1.0/accounts' => fn () => throw new ConnectionException('Timeout'),
    ]);

    $sandboxAccount = new MerchantAccountContextDto('10', 'revolut', ['access_token' => 'tok-ok'], true);
    $okHealth = $adapter->testConnection($sandboxAccount);
    expect($okHealth->healthy)->toBeTrue()
        ->and($okHealth->message)->toBe('Connected.');

    $failHealth = $adapter->testConnection($sandboxAccount);
    expect($failHealth->healthy)->toBeFalse()
        ->and($failHealth->message)->toBe('Provider connection failed.');

    $prodAccount = new MerchantAccountContextDto('10', 'revolut', ['access_token' => 'tok-prod'], false);
    $connErrHealth = $adapter->testConnection($prodAccount);
    expect($connErrHealth->healthy)->toBeFalse()
        ->and($connErrHealth->message)->toBe('Provider connection failed.');
});

it('fetches bank feed accounts and normalizes transactions from list and wrapped payloads', function (): void {
    config()->set('services.revolut.environment', 'sandbox');

    $connection = makeRevolutBankFeedConnection('cursor-42');

    Http::fake([
        'https://sandbox-b2b.revolut.com/api/1.0/accounts' => Http::response([['id' => 'acc-1', 'currency' => 'GBP']], 200),
        'https://sandbox-b2b.revolut.com/api/1.0/accounts/acc-1' => Http::response(['id' => 'acc-1', 'balance' => 1000], 200),
        'https://sandbox-b2b.revolut.com/api/1.0/transactions*' => Http::sequence()
            ->push([
                [
                    'id' => 'tx-neg',
                    'type' => 'CARD_PAYMENT',
                    'state' => 'pending',
                    'created_at' => '2026-08-21T12:00:00Z',
                    'merchant' => ['name' => 'Pret A Manger'],
                    'legs' => [['amount' => -12.50, 'currency' => 'GBP']],
                ],
                [
                    'id' => 'tx-pos',
                    'type' => 'TRANSFER',
                    'state' => 'completed',
                    'completed_at' => '2026-08-22T09:30:00Z',
                    'reference' => 'Client Retainer',
                    'amount' => 500.00,
                    'currency' => 'EUR',
                ],
            ], 200)
            ->push([
                'transactions' => [
                    [
                        'id' => 'tx-wrapped',
                        'amount' => 25,
                    ],
                ],
            ], 200),
    ]);

    $adapter = new RevolutAdapter($connection);

    expect($adapter->getAccounts($connection))->toBe([['id' => 'acc-1', 'currency' => 'GBP']])
        ->and($adapter->getAccount($connection, 'acc-1'))->toBe(['id' => 'acc-1', 'balance' => 1000]);

    $feed = $adapter->fetch($connection);
    expect($feed['modified'])->toBe([])
        ->and($feed['removed'])->toBe([])
        ->and($feed['next_cursor'])->toBe('cursor-42')
        ->and($feed['added'])->toHaveCount(2);

    expect($feed['added'][0])->toMatchArray([
        'external_id' => 'tx-neg',
        'transaction_date' => '2026-08-21',
        'amount' => -12.50,
        'currency' => 'GBP',
        'status' => 'pending',
        'description' => 'Pret A Manger',
        'category' => 'card_payment',
    ]);

    expect($feed['added'][1])->toMatchArray([
        'external_id' => 'tx-pos',
        'transaction_date' => '2026-08-22',
        'amount' => 500.00,
        'currency' => 'EUR',
        'status' => 'posted',
        'description' => 'Client Retainer',
        'category' => 'transfer',
    ]);

    $wrappedFeed = $adapter->fetch($connection);
    expect($wrappedFeed['added'])->toHaveCount(1)
        ->and($wrappedFeed['added'][0])->toMatchArray([
            'external_id' => 'tx-wrapped',
            'amount' => 25.0,
            'currency' => 'USD',
            'status' => 'posted',
            'description' => 'Revolut transaction',
            'category' => 'uncategorized',
        ]);
});

it('sends outbound single and bulk payments and guards missing connections or tokens', function (): void {
    config()->set('services.revolut.environment', 'sandbox');

    $unconnected = new RevolutAdapter();
    expect(fn () => $unconnected->sendPayment(['amount' => 10]))
        ->toThrow(\RuntimeException::class, 'A connection is required for payments.');
    expect(fn () => $unconnected->sendBulkPayments('Payroll', [['amount' => 10]]))
        ->toThrow(\RuntimeException::class, 'A connection is required for payments.');

    $noTokenAccount = new MerchantAccountContextDto('10', 'revolut', [], true);
    expect(fn () => $unconnected->sendOutboundPayment($noTokenAccount, ['amount' => 10]))
        ->toThrow(\InvalidArgumentException::class, 'An access token is required for payments.');

    Http::fake([
        'https://sandbox-b2b.revolut.com/api/1.0/pay' => Http::response(['id' => 'pay-1', 'state' => 'completed'], 200),
        'https://sandbox-b2b.revolut.com/api/1.0/payment-drafts' => Http::response(['id' => 'draft-1'], 200),
        'https://b2b.revolut.com/api/1.0/payment-drafts' => Http::response(['id' => 'draft-prod'], 200),
    ]);

    $connection = makeRevolutBankFeedConnection();
    $connected = new RevolutAdapter($connection);

    $single = $connected->sendPayment(['request_id' => 'req-1', 'amount' => 45.00, 'currency' => 'GBP']);
    expect($single)->toBe(['id' => 'pay-1', 'state' => 'completed']);

    $bulk = $connected->sendBulkPayments('Supplier Run', [['amount' => 45.00]], '2026-09-01');
    expect($bulk)->toBe(['id' => 'draft-1']);

    $prodAccount = new MerchantAccountContextDto('10', 'revolut', ['access_token' => 'prod-tok'], false);
    $prodBulk = $connected->sendOutboundBatch($prodAccount, 'Immediate Run', [['amount' => 90.00]]);
    expect($prodBulk)->toBe(['id' => 'draft-prod']);

    Http::assertSent(fn (Request $req): bool => $req->url() === 'https://sandbox-b2b.revolut.com/api/1.0/payment-drafts'
        && ($req['schedule_for'] ?? null) === '2026-09-01');
    Http::assertSent(fn (Request $req): bool => $req->url() === 'https://b2b.revolut.com/api/1.0/payment-drafts'
        && ! array_key_exists('schedule_for', $req->data()));
});
