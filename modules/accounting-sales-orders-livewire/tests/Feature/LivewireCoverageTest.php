<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\SalesOrders\Actions\TransitionSalesOrder;
use Liberu\Accounting\SalesOrders\Enums\OrderStatus;
use Liberu\Accounting\SalesOrders\Models\SalesOrder;
use Liberu\Accounting\SalesOrdersLivewire\Livewire\SalesOrders;
use Liberu\Accounting\SalesOrdersLivewire\SalesOrdersLivewireServiceProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('covers accounting-sales-orders-livewire components', function (): void {
    $provider = $this->app->register(SalesOrdersLivewireServiceProvider::class, force: true);
    $provider->boot();

    expect(fn () => (new SalesOrders())->render())->toThrow(HttpException::class);

    $this->actingAs(User::factory()->create(['current_team_id' => 1]));

    $order = SalesOrder::query()->create([
        'team_id' => 1,
        'order_number' => 'SO-001',
        'customer_id' => 1,
        'order_date' => '2026-01-01',
        'currency' => 'GBP',
        'subtotal' => 100.00,
        'tax_total' => 0.00,
        'total' => 100.00,
        'status' => OrderStatus::Draft,
    ]);

    $component = new SalesOrders();
    $component->selectOrder((int) $order->getKey());
    $component->status = 'confirmed';
    $component->transitionStatus(app(TransitionSalesOrder::class));
    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
    expect($component->render())->not->toBeNull();
});
