<?php

declare(strict_types=1);

namespace Liberu\Accounting\PaymentReconciliation\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Liberu\Accounting\PaymentReconciliation\Models\SettlementRun;

final class SettlementQuery
{
    public function paginate(?int $teamId = null, ?string $status = null, int $perPage = 25): LengthAwarePaginator
    {
        return SettlementRun::query()->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))->when($status, fn ($q) => $q->where('status', $status))->latest()->paginate(min(max($perPage, 1), 100));
    }

    /** @return array<string,mixed> */
    public function summary(?int $teamId = null): array
    {
        $runs = SettlementRun::query()->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))->get();

        return [
            'count' => $runs->count(),
            'gross_amount' => (float) $runs->sum('gross_amount'),
            'fees' => (float) $runs->sum('fee_amount'),
            'refunds' => (float) $runs->sum('refund_amount'),
            'disputes' => (float) $runs->sum('dispute_amount'),
            'net_amount' => (float) $runs->sum('net_amount'),
            'matched' => $runs->filter(static fn (SettlementRun $r): bool => in_array($r->status?->value, ['matched', 'reconciled'], true))->count(),
            'exceptions' => $runs->filter(static fn (SettlementRun $r): bool => $r->status?->value === 'exception')->count(),
        ];
    }
}
