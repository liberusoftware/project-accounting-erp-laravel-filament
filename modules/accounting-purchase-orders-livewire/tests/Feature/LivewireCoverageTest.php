<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PurchaseOrders\Actions\TransitionPurchaseOrder;
use Liberu\Accounting\PurchaseOrders\Enums\PurchaseOrderStatus;
use Liberu\Accounting\PurchaseOrders\Models\PurchaseOrder;
use Liberu\Accounting\PurchaseOrdersLivewire\Livewire\PurchaseOrders;
use Liberu\Accounting\PurchaseOrdersLivewire\PurchaseOrdersLivewireServiceProvider;

it('covers accounting-purchase-orders-livewire components', function (): void {
    $provider = $this->app->register(PurchaseOrdersLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create(['current_team_id' => 1]));

    $order = PurchaseOrder::query()->create([
        'team_id' => 1,
        'order_number' => 'PO-001',
        'supplier_ref' => 'SUP-1',
        'currency' => 'GBP',
        'order_date' => '2026-01-01',
        'total_amount' => 100.00,
        'status' => PurchaseOrderStatus::Draft,
    ]);

    $component = new PurchaseOrders();
    $component->selectOrder((int) $order->getKey());
    $component->status = 'pending_approval';
    $component->transitionStatus(app(TransitionPurchaseOrder::class));
    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::PendingApproval);
    expect($component->render())->not->toBeNull();
});
