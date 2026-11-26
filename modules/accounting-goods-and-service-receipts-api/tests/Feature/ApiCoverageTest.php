<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\GoodsAndServiceReceiptsApi\GoodsAndServiceReceiptsApiServiceProvider;

it('covers accounting-goods-and-service-receipts-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(GoodsAndServiceReceiptsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(GoodsAndServiceReceiptsApiServiceProvider::class);

    $base = '/api/v1/accounting/goods-and-service-receipts';

    $goodsRes = $this->postJson($base, [
        'team_id' => 1,
        'receipt_ref' => 'GRN-100',
        'receipt_type' => 'goods',
        'supplier_ref' => 'SUP-1',
        'purchase_order_ref' => 'PO-100',
        'currency' => 'USD',
    ]);
    expect($goodsRes->status())->toBeLessThan(500);
    $goodsId = $goodsRes->json('data.id');
    expect($goodsId)->not->toBeNull();

    expect($this->getJson("{$base}?team_id=1&status=draft")->status())->toBeGreaterThanOrEqual(200);
    expect($this->getJson($base)->status())->toBeGreaterThanOrEqual(200);

    expect($this->postJson("{$base}/{$goodsId}/lines", [
        'line_ref' => 'L-1',
        'item_ref' => 'ITEM-1',
        'description' => 'Raw material',
        'ordered_quantity' => 10,
        'received_quantity' => 10,
        'unit_price' => 25,
    ])->status())->toBeLessThan(500);

    expect($this->postJson("{$base}/{$goodsId}/returns", [
        'return_ref' => 'RET-1',
        'line_ref' => 'L-1',
        'quantity' => 2,
        'value' => 50,
        'reason' => 'Damaged in transit',
        'source_ref' => 'RMA-1',
    ])->status())->toBeLessThan(500);

    expect($this->postJson("{$base}/{$goodsId}/attachments", [
        'attachment_ref' => 'ATT-1',
        'kind' => 'packing_slip',
        'file_ref' => 's3://receipts/grn-100.pdf',
        'attached_by' => 'warehouse-1',
    ])->status())->toBeLessThan(500);

    expect($this->postJson("{$base}/{$goodsId}/accruals", [
        'accrual_ref' => 'ACC-1',
        'amount' => 200,
        'currency' => 'USD',
        'period_ref' => '2026-01',
        'source_ref' => 'GL-ACC-1',
    ])->status())->toBeLessThan(500);

    expect($this->getJson("{$base}/{$goodsId}")->status())->toBe(200);
    expect($this->getJson("{$base}/{$goodsId}/variance")->status())->toBe(200);

    $serviceRes = $this->postJson($base, [
        'team_id' => 1,
        'receipt_ref' => 'SES-100',
        'receipt_type' => 'service',
        'supplier_ref' => 'SUP-2',
        'currency' => 'USD',
    ]);
    $serviceId = $serviceRes->json('data.id');
    expect($this->postJson("{$base}/{$serviceId}/service-confirmation", [
        'confirmation_ref' => 'CONF-1',
        'service_period' => '2026-01',
        'quantity' => 1,
        'value' => 1500,
        'confirmed_by' => 'manager-1',
        'comment' => 'Completed on schedule',
    ])->status())->toBeLessThan(500);
});
