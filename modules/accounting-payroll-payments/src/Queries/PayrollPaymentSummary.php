<?php

declare(strict_types=1);

namespace Liberu\Accounting\PayrollPayments\Queries;

use Liberu\Accounting\PayrollPayments\Models\PayrollPaymentBatch;

final class PayrollPaymentSummary
{
    /** @return array<string,mixed> */
    public function forTeam(?int $teamId = null): array
    {
        $rows = PayrollPaymentBatch::query()->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))->get();

        return [
            'count' => $rows->count(),
            'total_amount' => (float) $rows->sum(fn (PayrollPaymentBatch $batch): float => $batch->totalAmount()),
            'pending' => $rows->filter(static fn (PayrollPaymentBatch $b): bool => in_array($b->status?->value, ['draft', 'approved', 'submitted'], true))->count(),
            'failed' => $rows->filter(static fn (PayrollPaymentBatch $b): bool => $b->status?->value === 'failed')->count(),
            'reconciled' => $rows->filter(static fn (PayrollPaymentBatch $b): bool => $b->status?->value === 'reconciled')->count(),
        ];
    }
}
