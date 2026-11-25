<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\BankFeeds\Models\BankFeedConnection;
use Liberu\Accounting\BankFeeds\Models\BankFeedInstitution;
use Liberu\Accounting\Wise\WiseBankFeedAdapter;
use Liberu\Accounting\Wise\WiseServiceProvider;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\PaymentCapabilityDescriptorDto;

function makeWiseConnection(array $overrides = []): BankFeedConnection
{
    $institution = BankFeedInstitution::create([
        'provider' => 'wise',
        'external_id' => 'wise-inst-'.uniqid(),
        'name' => 'Wise Business',
        'country' => 'GB',
    ]);

    return BankFeedConnection::create(array_merge([
        'team_id' => 10,
        'institution_id' => $institution->id,
        'provider' => 'wise',
        'name' => 'Wise Main',
        'external_reference' => 'wise-conn-'.uniqid(),
        'access_token' => 'wise-access-token',
        'credentials' => ['profile_id' => 555],
        'cursor' => 'cur-1',
    ], $overrides));
}

it('registers wise service provider and registers adapter in integration and payment registries', function (): void {
    expect(app()->getProvider(WiseServiceProvider::class))->toBeInstanceOf(WiseServiceProvider::class);
    expect(Schema::hasTable('accounting_bank_feed_connections'))->toBeTrue();

    $adapter = app(WiseBankFeedAdapter::class);
    expect($adapter)->toBe(app(WiseBankFeedAdapter::class))
        ->and(app(IntegrationRegistry::class)->get('wise'))->toBe($adapter)
        ->and(app(PaymentGatewayRegistryContract::class)->resolve('wise'))->toBe($adapter);
});

it('exposes identity, capabilities, descriptor, and credential test helper', function (): void {
    $adapter = new WiseBankFeedAdapter();

    expect($adapter->name())->toBe('wise')
        ->and($adapter->providerKey())->toBe('wise')
        ->and($adapter->capabilities())->toBe(['bank-feeds', 'profiles', 'balances', 'transfers', 'oauth']);

    $descriptor = $adapter->descriptor();
    expect($descriptor)->toBeInstanceOf(PaymentCapabilityDescriptorDto::class)
        ->and($descriptor->providerKey)->toBe('wise')
        ->and($descriptor->displayName)->toBe('Wise')
        ->and($descriptor->supportedMethods)->toBe(['bank_transfer'])
        ->and($descriptor->supportsAuthorizeOnly)->toBeFalse();

    expect($adapter->test(['client_id' => 'cid', 'client_secret' => 'sec']))->toBeTrue()
        ->and($adapter->test(['client_id' => 'cid']))->toBeFalse()
        ->and($adapter->test(['client_secret' => 'sec']))->toBeFalse()
        ->and($adapter->test([]))->toBeFalse();
});

it('builds sandbox and production authorization urls and exchanges or refreshes oauth tokens', function (): void {
    $adapter = new WiseBankFeedAdapter();

    config()->set('services.wise.environment', 'sandbox');
    config()->set('services.wise.client_id', 'wise-client-id');
    config()->set('services.wise.client_secret', 'wise-client-secret');
    config()->set('services.wise.redirect_uri', 'https://app.test/oauth/wise/callback');

    $sandboxAuthUrl = $adapter->authorizationUrl('state-123');
    expect($sandboxAuthUrl)->toStartWith('https://sandbox.transferwise.tech/oauth/v2/authorize?')
        ->and($sandboxAuthUrl)->toContain('client_id=wise-client-id')
        ->and($sandboxAuthUrl)->toContain('state=state-123');

    config()->set('services.wise.environment', 'production');
    expect($adapter->authorizationUrl('state-456'))
        ->toStartWith('https://wise.com/oauth/v2/authorize?');

    Http::fake([
        'https://api.transferwise.com/oauth/v2/token' => Http::response(['access_token' => 'new-tok', 'refresh_token' => 'ref-tok'], 200),
    ]);

    $exchanged = $adapter->exchangeAuthorizationCode('auth-code-1');
    $refreshed = $adapter->refreshAccessToken('ref-tok');

    expect($exchanged['access_token'])->toBe('new-tok')
        ->and($refreshed['refresh_token'])->toBe('ref-tok');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.transferwise.com/oauth/v2/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code-1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.transferwise.com/oauth/v2/token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'ref-tok');
});

it('tests merchant connection health across missing token, success, failure, and connection exception', function (): void {
    $adapter = new WiseBankFeedAdapter();

    $missingToken = new MerchantAccountContextDto('1', 'wise', ['access_token' => '   '], sandbox: true);
    $missingHealth = $adapter->testConnection($missingToken);
    expect($missingHealth->healthy)->toBeFalse()
        ->and($missingHealth->message)->toBe('An access token is required.');

    Http::fake([
        'https://api.sandbox.transferwise.tech/v2/profiles' => Http::sequence()
            ->push([['id' => 1]], 200)
            ->push(['error' => 'unauthorized'], 401)
            ->whenEmpty(fn () => throw new ConnectionException('Network unreachable')),
    ]);

    $sandboxAccount = new MerchantAccountContextDto('1', 'wise', ['access_token' => 'tok-1'], sandbox: true);
    $ok = $adapter->testConnection($sandboxAccount);
    $unauthorized = $adapter->testConnection($sandboxAccount);
    $unreachable = $adapter->testConnection($sandboxAccount);

    expect($ok->healthy)->toBeTrue()->and($ok->message)->toBe('Connected.')
        ->and($unauthorized->healthy)->toBeFalse()->and($unauthorized->message)->toBe('Provider connection failed.')
        ->and($unreachable->healthy)->toBeFalse()->and($unreachable->message)->toBe('Provider connection failed.');
});

it('fetches profiles, balances, and bank feed transfers including empty profile and list or wrapped responses', function (): void {
    config()->set('services.wise.environment', 'sandbox');
    $adapter = new WiseBankFeedAdapter();

    $noProfileConn = makeWiseConnection(['credentials' => []]);
    expect($adapter->fetch($noProfileConn))->toBe([
        'added' => [],
        'modified' => [],
        'removed' => [],
        'next_cursor' => 'cur-1',
    ]);

    $conn = makeWiseConnection();

    Http::fake([
        'https://api.sandbox.transferwise.tech/v2/profiles' => Http::response([['id' => 555, 'type' => 'business']], 200),
        'https://api.sandbox.transferwise.tech/v4/profiles/555/balances*' => Http::response([['id' => 9, 'currency' => 'EUR']], 200),
        'https://api.sandbox.transferwise.tech/v1/transfers*' => Http::sequence()
            ->push([
                [
                    'id' => 1001,
                    'createdDate' => '2026-04-01T12:34:56Z',
                    'targetValue' => 125.50,
                    'targetCurrency' => 'EUR',
                    'status' => 'outgoing_payment_sent',
                    'reference' => 'Vendor payout',
                ],
                [
                    'id' => 1002,
                    'createdDate' => '2026-04-02T08:00:00Z',
                    'sourceValue' => 50,
                    'sourceCurrency' => 'GBP',
                    'status' => 'processing',
                ],
            ], 200)
            ->push([
                'transfers' => [
                    [
                        'id' => 1003,
                        'createdDate' => '2026-04-03T10:00:00Z',
                        'targetValue' => 75,
                        'targetCurrency' => 'USD',
                        'status' => 'completed',
                        'reference' => 'Wrapped payout',
                    ],
                ],
            ], 200),
    ]);

    expect($adapter->getProfiles($conn))->toBe([['id' => 555, 'type' => 'business']]);
    expect($adapter->getBalances($conn, 555, 'SAVINGS'))->toBe([['id' => 9, 'currency' => 'EUR']]);

    $firstFetch = $adapter->fetch($conn);
    expect($firstFetch['added'])->toHaveCount(2)
        ->and($firstFetch['added'][0]['external_id'])->toBe('1001')
        ->and($firstFetch['added'][0]['transaction_date'])->toBe('2026-04-01')
        ->and($firstFetch['added'][0]['amount'])->toBe(-125.5)
        ->and($firstFetch['added'][0]['currency'])->toBe('EUR')
        ->and($firstFetch['added'][0]['status'])->toBe('posted')
        ->and($firstFetch['added'][0]['description'])->toBe('Vendor payout')
        ->and($firstFetch['added'][1]['status'])->toBe('pending')
        ->and($firstFetch['added'][1]['description'])->toBe('Wise transfer')
        ->and($firstFetch['next_cursor'])->toBe('cur-1');

    $wrappedFetch = $adapter->fetch($conn);
    expect($wrappedFetch['added'])->toHaveCount(1)
        ->and($wrappedFetch['added'][0]['external_id'])->toBe('1003')
        ->and($wrappedFetch['added'][0]['status'])->toBe('posted');
});

it('verifies rsa sha256 webhook signatures', function (): void {
    $adapter = new WiseBankFeedAdapter();
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    $publicKey = openssl_pkey_get_details($keyPair)['key'];

    $payload = '{"event_type":"transfers#state-change"}';
    openssl_sign($payload, $rawSig, $privateKey, OPENSSL_ALGO_SHA256);
    $signature = base64_encode($rawSig);

    expect($adapter->verifyWebhookSignature($payload, $signature, $publicKey))->toBeTrue()
        ->and($adapter->verifyWebhookSignature($payload.' ', $signature, $publicKey))->toBeFalse()
        ->and($adapter->verifyWebhookSignature($payload, '', $publicKey))->toBeFalse()
        ->and($adapter->verifyWebhookSignature($payload, $signature, ''))->toBeFalse()
        ->and($adapter->verifyWebhookSignature($payload, '!!!', $publicKey))->toBeFalse()
        ->and($adapter->verifyWebhookSignature($payload, $signature, 'not-a-key'))->toBeFalse();
});

it('sends single and bulk outbound payments and enforces validation guards', function (): void {
    config()->set('services.wise.environment', 'sandbox');

    $unconnected = new WiseBankFeedAdapter();
    expect(fn () => $unconnected->sendPayment(['customerTransactionId' => 'tx-1']))
        ->toThrow(RuntimeException::class, 'A connection is required for payments.');
    expect(fn () => $unconnected->sendBulkPayments('Batch', [['customerTransactionId' => 'tx-1']]))
        ->toThrow(RuntimeException::class, 'A connection is required for payments.');

    $accountNoToken = new MerchantAccountContextDto('1', 'wise', [], sandbox: true);
    expect(fn () => $unconnected->sendOutboundPayment($accountNoToken, ['customerTransactionId' => 'tx-1']))
        ->toThrow(InvalidArgumentException::class, 'An access token is required for payments.');

    $account = new MerchantAccountContextDto('1', 'wise', ['access_token' => 'pay-tok'], sandbox: true);
    expect(fn () => $unconnected->sendOutboundPayment($account, []))
        ->toThrow(InvalidArgumentException::class, 'A customerTransactionId is required for Wise transfers.');

    expect(fn () => $unconnected->sendOutboundBatch($account, 'Scheduled', [['customerTransactionId' => 'tx-1']], '2026-05-01'))
        ->toThrow(InvalidArgumentException::class, 'Wise transfer creation does not support scheduled batches.');

    $tooMany = array_fill(0, 101, ['customerTransactionId' => 'tx']);
    expect(fn () => $unconnected->sendOutboundBatch($account, 'Too Large', $tooMany))
        ->toThrow(InvalidArgumentException::class, 'Wise bulk submissions are limited to 100 transfers.');

    Http::fake([
        'https://api.sandbox.transferwise.tech/v1/transfers' => Http::response(['id' => 777, 'status' => 'incoming_payment_waiting'], 200),
    ]);

    expect(fn () => $unconnected->sendOutboundBatch($account, 'Invalid item', [
        ['customerTransactionId' => 'ok-1'],
        ['customerTransactionId' => ''],
    ]))->toThrow(InvalidArgumentException::class, 'A customerTransactionId is required for Wise transfers.');
    Http::assertNothingSent();

    $conn = makeWiseConnection();
    $connectedAdapter = new WiseBankFeedAdapter($conn);

    $single = $connectedAdapter->sendPayment(['customerTransactionId' => 'tx-single', 'targetAccount' => 1]);
    $bulk = $connectedAdapter->sendBulkPayments('May Payroll', [
        ['customerTransactionId' => 'tx-b1'],
        ['customerTransactionId' => 'tx-b2'],
    ]);

    expect($single['id'])->toBe(777)
        ->and($bulk['title'])->toBe('May Payroll')
        ->and($bulk['payments'])->toHaveCount(2);

    Http::assertSentCount(3);
});
