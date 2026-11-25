<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Core\Services\PaymentOrchestrator;

it('routes Revolut and Wise outbound payments using the supplied connection context', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://sandbox-b2b.revolut.com/api/1.0/pay' => Http::response(['id' => 'revolut-payment'], 201),
        'https://api.transferwise.com/v1/transfers' => Http::response(['id' => 42], 201),
    ]);

    $gateways = app(PaymentGatewayRegistryContract::class);
    $integrations = app(IntegrationRegistry::class);
    expect($gateways->resolve('revolut'))->toBe($integrations->oauth('revolut'))
        ->and($gateways->resolve('wise'))->toBe($integrations->oauth('wise'));

    $payments = app(PaymentOrchestrator::class);
    $revolut = new MerchantAccountContextDto('5', 'revolut', ['access_token' => 'revolut-token'], sandbox: true);
    $wise = new MerchantAccountContextDto('6', 'wise', ['access_token' => 'wise-token']);

    expect($payments->sendOutboundPayment($revolut, ['request_id' => 'approved-payment'])['id'])
        ->toBe('revolut-payment');
    expect($payments->sendOutboundBatch($wise, 'Supplier transfers', [['customerTransactionId' => 'transfer-1']]))
        ->toBe(['title' => 'Supplier transfers', 'payments' => [['id' => 42]]]);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer revolut-token')
        && $request['request_id'] === 'approved-payment');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer wise-token')
        && $request['customerTransactionId'] === 'transfer-1');
});

it('rejects outbound calls without a connection token before making a provider request', function (): void {
    Http::fake();

    expect(fn () => app(PaymentOrchestrator::class)->sendOutboundPayment(
        new MerchantAccountContextDto('5', 'wise', sandbox: true),
        ['customerTransactionId' => 'local-transfer'],
    ))->toThrow(InvalidArgumentException::class, 'An access token is required for payments.');

    Http::assertNothingSent();
});

it('validates Wise batches before submitting any transfers', function (): void {
    Http::fake();
    $account = new MerchantAccountContextDto('6', 'wise', ['access_token' => 'wise-token']);
    $payments = app(PaymentOrchestrator::class);
    $transfer = ['customerTransactionId' => 'transfer-1'];

    expect(fn () => $payments->sendOutboundBatch($account, 'Batch', [$transfer], '2026-12-01'))
        ->toThrow(InvalidArgumentException::class, 'Wise transfer creation does not support scheduled batches.')
        ->and(fn () => $payments->sendOutboundBatch($account, 'Batch', [$transfer, []]))
        ->toThrow(InvalidArgumentException::class, 'A customerTransactionId is required for Wise transfers.');

    Http::assertNothingSent();
});
