<?php

declare(strict_types=1);

use Liberu\Accounting\AutomationPack\Actions\CreateAutomationRecipe;
use Liberu\Accounting\AutomationPack\Actions\SimulateAutomationRecipe;
use Liberu\Accounting\AutomationPack\AutomationPackServiceProvider;
use Liberu\Accounting\AutomationPack\Models\AutomationRecipe;

it('boots the service provider and covers model, create action, and simulate action', function (): void {
    $provider = $this->app->register(AutomationPackServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AutomationPackServiceProvider::class);

    $create = app(CreateAutomationRecipe::class);
    $simulate = app(SimulateAutomationRecipe::class);

    // Default draft status
    $draftRecipe = $create->handle([
        'team_id' => 1,
        'name' => 'Auto-Match Receipts',
        'trigger' => 'bank_transaction.imported',
        'action' => 'reconcile_match',
        'schedule' => '0 * * * *',
        'idempotency_key' => 'recipe-1',
        'configuration' => ['tolerance' => 0.05],
    ]);

    expect($draftRecipe)->toBeInstanceOf(AutomationRecipe::class)
        ->and($draftRecipe->status)->toBe('draft')
        ->and($draftRecipe->configuration)->toBe(['tolerance' => 0.05])
        ->and($draftRecipe->schedule)->toBe('0 * * * *');

    $draftSimulation = $simulate->handle($draftRecipe, ['tx_id' => 123]);
    expect($draftSimulation)->toBe([
        'recipe_id' => $draftRecipe->id,
        'action' => 'reconcile_match',
        'payload' => ['tx_id' => 123],
        'would_execute' => false,
    ]);

    // Active status
    $activeRecipe = $create->handle([
        'team_id' => 1,
        'name' => 'Auto-Post Recurring',
        'trigger' => 'schedule.daily',
        'action' => 'post_recurring_journal',
        'status' => 'active',
        'idempotency_key' => 'recipe-2',
    ]);

    $activeSimulation = $simulate->handle($activeRecipe);
    expect($activeSimulation)->toBe([
        'recipe_id' => $activeRecipe->id,
        'action' => 'post_recurring_journal',
        'payload' => [],
        'would_execute' => true,
    ]);
});
