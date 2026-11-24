<?php

declare(strict_types=1);

namespace Liberu\Platform\PlatformOrchestration\Exceptions;

use DomainException;
use Liberu\Platform\PlatformOrchestration\Enums\CompositionState;

final class InvalidCompositionTransition extends DomainException
{
    public function __construct(CompositionState $from, CompositionState $to)
    {
        parent::__construct("Composition cannot transition from {$from->value} to {$to->value}.");
    }
}
