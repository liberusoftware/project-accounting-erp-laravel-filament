<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\BankRules\Models\BankRule;
use Liberu\Accounting\BankRulesLivewire\BankRulesLivewireServiceProvider;
use Liberu\Accounting\BankRulesLivewire\Livewire\Rules;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-bank-rules-livewire components', function (): void {
    $provider = $this->app->register(BankRulesLivewireServiceProvider::class, force: true);
    $provider->boot();

    expect(fn () => (new Rules())->mount())->toThrow(AuthorizationException::class);

    $user = User::factory()->create(['current_team_id' => 1]);
    $this->actingAs($user);

    $rule = BankRule::query()->create([
        'team_id' => 1,
        'name' => 'Coffee Rule',
        'priority' => 10,
        'enabled' => true,
        'conditions' => ['text' => 'coffee'],
        'actions' => ['category' => 'meals'],
        'automation_mode' => 'suggest',
    ]);

    $component = new Rules();
    $component->mount();
    $component->transactionText = 'Morning coffee shop';
    $component->testRule((int) $rule->getKey());
    expect($component->testResult)->toBe('Rule matched.');

    $component->transactionText = 'Office supplies';
    $component->testRule((int) $rule->getKey());
    expect($component->testResult)->toBe('Rule did not match.');
    expect($component->render())->not->toBeNull();
});
