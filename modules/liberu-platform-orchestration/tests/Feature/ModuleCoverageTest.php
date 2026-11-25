<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Liberu\Platform\PlatformOrchestration\Actions\CreateComposition;
use Liberu\Platform\PlatformOrchestration\Actions\CreatePlatformWorkflow;
use Liberu\Platform\PlatformOrchestration\Actions\TransitionComposition;
use Liberu\Platform\PlatformOrchestration\Actions\TransitionPlatformWorkflow;
use Liberu\Platform\PlatformOrchestration\Capability;
use Liberu\Platform\PlatformOrchestration\Enums\CompositionState;
use Liberu\Platform\PlatformOrchestration\Enums\LifecycleStatus;
use Liberu\Platform\PlatformOrchestration\Events\CompositionCreated;
use Liberu\Platform\PlatformOrchestration\Events\CompositionStateChanged;
use Liberu\Platform\PlatformOrchestration\Events\PlatformWorkflowTransitioned;
use Liberu\Platform\PlatformOrchestration\Exceptions\InvalidCompositionTransition;
use Liberu\Platform\PlatformOrchestration\Exceptions\InvalidLifecycleTransition;
use Liberu\Platform\PlatformOrchestration\Models\PlatformComposition;
use Liberu\Platform\PlatformOrchestration\Models\PlatformWorkflow;
use Liberu\Platform\PlatformOrchestration\PlatformOrchestrationServiceProvider;

it('boots platform orchestration provider and covers capability and enums', function (): void {
    $provider = $this->app->register(PlatformOrchestrationServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    $capability = app(Capability::class);

    expect($capability->name)->toBe('liberu-platform-orchestration')
        ->and($capability->supports('liberu.platform-orchestration'))->toBeTrue()
        ->and($capability->supports('liberu.platform-orchestration.lifecycle'))->toBeTrue()
        ->and($capability->supports('unsupported'))->toBeFalse()
        ->and(LifecycleStatus::Draft->allowedTransitions())->toBe([LifecycleStatus::Active, LifecycleStatus::Archived])
        ->and(LifecycleStatus::Active->allowedTransitions())->toBe([LifecycleStatus::Completed, LifecycleStatus::Archived])
        ->and(LifecycleStatus::Completed->allowedTransitions())->toBe([])
        ->and(LifecycleStatus::Archived->allowedTransitions())->toBe([])
        ->and(array_column(CompositionState::cases(), 'value'))->toBe([
            'draft', 'installed', 'enabled', 'entitled', 'active', 'disabled', 'failed',
        ]);
});

it('creates and transitions platform workflows and platform compositions with full validation', function (): void {
    Event::fake([
        PlatformWorkflowTransitioned::class,
        CompositionCreated::class,
        CompositionStateChanged::class,
    ]);

    $workflow = app(CreatePlatformWorkflow::class)->execute([
        'tenant_id' => 'tenant-33',
        'idempotency_key' => 'wf-1',
        'name' => 'Provision Tenant Stack',
        'metadata' => ['region' => 'eu-west-1'],
    ]);

    expect($workflow)->toBeInstanceOf(PlatformWorkflow::class)
        ->and($workflow->status)->toBe('draft')
        ->and($workflow->metadata)->toBe(['region' => 'eu-west-1'])
        ->and(PlatformWorkflow::query()->forTenant('tenant-33')->count())->toBe(1)
        ->and(PlatformWorkflow::query()->forTenant('tenant-00')->count())->toBe(0);

    $transitionWorkflow = app(TransitionPlatformWorkflow::class);
    $activeWf = $transitionWorkflow->execute($workflow, LifecycleStatus::Active);
    $completedWf = $transitionWorkflow->execute($activeWf, LifecycleStatus::Completed);
    expect($completedWf->status)->toBe('completed');

    expect(fn () => $transitionWorkflow->execute($completedWf, LifecycleStatus::Draft))
        ->toThrow(InvalidLifecycleTransition::class, 'Cannot transition platform workflow from [completed] to [draft].');

    $wfEvent = new PlatformWorkflowTransitioned((string) $workflow->getKey(), 'tenant-33', 'draft', 'active');
    expect($wfEvent->to)->toBe('active');

    $createComposition = app(CreateComposition::class);

    // Invalid manifests
    expect(fn () => $createComposition->handle([
        'key' => 'bad-1',
        'display_name' => 'Bad 1',
        'application' => 'accounting',
        'manifest' => ['applications' => [], 'modules' => ['core']],
    ]))->toThrow(ValidationException::class);

    expect(fn () => $createComposition->handle([
        'key' => 'bad-2',
        'display_name' => 'Bad 2',
        'application' => 'accounting',
        'manifest' => ['applications' => ['accounting'], 'modules' => ['  ']],
    ]))->toThrow(ValidationException::class);

    $globalComp = $createComposition->handle([
        'team_id' => null,
        'key' => 'erp-core',
        'display_name' => 'ERP Core Stack',
        'application' => 'accounting',
        'manifest' => [
            'applications' => ['accounting'],
            'modules' => ['accounting-core', 'audit'],
        ],
        'metadata' => ['tier' => 'enterprise'],
    ]);

    $teamComp = $createComposition->handle([
        'team_id' => 9,
        'key' => 'erp-team-9',
        'display_name' => 'Team 9 Stack',
        'application' => 'accounting',
        'manifest' => [
            'applications' => ['accounting'],
            'modules' => ['feature-flags'],
        ],
    ]);

    expect($globalComp->state)->toBe(CompositionState::Draft)
        ->and($globalComp->metadata)->toBe(['tier' => 'enterprise'])
        ->and($teamComp->metadata)->toBe([])
        ->and(PlatformComposition::query()->forTeam(null)->count())->toBe(1)
        ->and(PlatformComposition::query()->forTeam(9)->count())->toBe(2)
        ->and(PlatformComposition::query()->forTeam(10)->count())->toBe(1);

    Event::assertDispatched(CompositionCreated::class, fn (CompositionCreated $e): bool => $e->composition->is($globalComp));

    $transitionComp = app(TransitionComposition::class);
    $comp = $transitionComp->handle($globalComp, CompositionState::Installed);
    $comp = $transitionComp->handle($comp, CompositionState::Enabled);
    $comp = $transitionComp->handle($comp, CompositionState::Entitled);
    $comp = $transitionComp->handle($comp, CompositionState::Active);
    $comp = $transitionComp->handle($comp, CompositionState::Disabled);
    $comp = $transitionComp->handle($comp, CompositionState::Failed);
    $comp = $transitionComp->handle($comp, CompositionState::Draft);

    expect($comp->state)->toBe(CompositionState::Draft);

    Event::assertDispatched(CompositionStateChanged::class, fn (CompositionStateChanged $e): bool => $e->from === CompositionState::Draft && $e->to === CompositionState::Installed);

    expect(fn () => $transitionComp->handle($comp, CompositionState::Active))
        ->toThrow(InvalidCompositionTransition::class, 'Composition cannot transition from draft to active.');
});
