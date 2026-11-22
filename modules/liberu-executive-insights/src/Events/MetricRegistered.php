<?php

declare(strict_types=1);

namespace Liberu\Platform\ExecutiveInsights\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Liberu\Platform\ExecutiveInsights\Models\MetricDefinition;

final class MetricRegistered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly MetricDefinition $metric) {}
}
