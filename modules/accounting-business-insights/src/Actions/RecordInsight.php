<?php

declare(strict_types=1);

namespace Liberu\Accounting\BusinessInsights\Actions;

use Carbon\CarbonImmutable;
use Liberu\Accounting\BusinessInsights\Models\InsightSnapshot;

final class RecordInsight
{
    public function handle(array $attributes): InsightSnapshot
    {
        foreach (['team_id', 'metric', 'period_start', 'period_end', 'value'] as $field) {
            if (blank($attributes[$field] ?? null)) {
                throw new \InvalidArgumentException("{$field} is required.");
            }
        }

        $existing = InsightSnapshot::query()
            ->where('team_id', $attributes['team_id'])
            ->where('metric', $attributes['metric'])
            ->whereDate('period_start', $attributes['period_start'])
            ->whereDate('period_end', $attributes['period_end'])
            ->first();

        $payload = [...$attributes, 'refreshed_at' => CarbonImmutable::now()];

        if ($existing !== null) {
            $existing->update($payload);

            return $existing->refresh();
        }

        return InsightSnapshot::create($payload);
    }
}
