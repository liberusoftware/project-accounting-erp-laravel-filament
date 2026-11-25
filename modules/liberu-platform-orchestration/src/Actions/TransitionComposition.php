<?php

declare(strict_types=1);

namespace Liberu\Platform\PlatformOrchestration\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Liberu\Platform\PlatformOrchestration\Enums\CompositionState;
use Liberu\Platform\PlatformOrchestration\Events\CompositionStateChanged;
use Liberu\Platform\PlatformOrchestration\Exceptions\InvalidCompositionTransition;
use Liberu\Platform\PlatformOrchestration\Models\PlatformComposition;

final class TransitionComposition
{
    public function __construct(private readonly Dispatcher $events) {}

    public function handle(PlatformComposition $composition, CompositionState $state): PlatformComposition
    {
        if (! $composition->canTransitionTo($state)) {
            throw new InvalidCompositionTransition($composition->state, $state);
        }

        return DB::transaction(function () use ($composition, $state): PlatformComposition {
            $from = $composition->state;
            $composition->update(['state' => $state]);
            $composition->refresh();
            $this->events->dispatch(new CompositionStateChanged($composition, $from, $state));

            return $composition;
        });
    }
}
