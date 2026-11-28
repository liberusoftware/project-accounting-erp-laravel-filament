<?php

declare(strict_types=1);

use Liberu\Accounting\BankRules\Actions\RecordBankRuleDecision;
use Liberu\Accounting\BankRules\Actions\SaveBankRule;
use Liberu\Accounting\BankRules\Actions\TestBankRule;
use Liberu\Accounting\BankRules\BankRulesServiceProvider;
use Liberu\Accounting\BankRules\Enums\BankRuleAutomationMode;
use Liberu\Accounting\BankRules\Exceptions\InvalidBankRule;
use Liberu\Accounting\BankRules\Models\BankRuleHistory;
use Liberu\Accounting\BankRules\Queries\BankRuleQuery;

it('boots bank rules service provider and covers enum and exception', function (): void {
    $provider = $this->app->register(BankRulesServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(BankRulesServiceProvider::class)
        ->and(array_column(BankRuleAutomationMode::cases(), 'value'))->toBe(['disabled', 'suggest', 'automatic'])
        ->and((new InvalidBankRule('Bad rule'))->getMessage())->toBe('Bad rule');
});

it('saves, updates, queries, tests, and records decisions for bank rules', function (): void {
    $save = app(SaveBankRule::class);
    $query = app(BankRuleQuery::class);
    $tester = app(TestBankRule::class);
    $recorder = app(RecordBankRuleDecision::class);

    expect($query->forTeam(1)->get())->toHaveCount(0);

    $highPriority = $save->handle([
        'team_id' => 1,
        'name' => 'AWS Hosting',
        'priority' => 10,
        'enabled' => true,
        'automation_mode' => BankRuleAutomationMode::Automatic,
        'conditions' => [
            'text' => 'amazon web services',
            'payee' => 'aws',
            'amount_min' => 10,
            'amount_max' => 500,
            'account_id' => 7,
        ],
        'actions' => [
            'category' => 'hosting',
            'splits' => [
                ['account_id' => 101, 'percentage' => 60],
                ['account_id' => 102, 'percentage' => 40],
            ],
        ],
        'metadata' => ['tag' => 'cloud'],
    ]);

    $disabledRule = $save->handle([
        'team_id' => 1,
        'name' => 'Disabled Rule',
        'priority' => 20,
        'enabled' => false,
        'automation_mode' => 'disabled',
        'conditions' => ['text' => 'amazon'],
        'actions' => ['category' => 'misc'],
    ]);

    // Update existing rule keeping same name and clamping negative priority to 0
    $updated = $save->handle([
        'name' => 'AWS Hosting',
        'priority' => -5,
        'conditions' => $highPriority->conditions,
        'actions' => $highPriority->actions,
    ], $highPriority);

    expect($updated->priority)->toBe(0)
        ->and($updated->automation_mode)->toBe(BankRuleAutomationMode::Suggest)
        ->and($updated->enabled)->toBeTrue()
        ->and($updated->metadata)->toBe(['tag' => 'cloud']);

    $forTeam = $query->forTeam(1)->get();
    expect($forTeam->pluck('id')->all())->toBe([$disabledRule->getKey(), $updated->getKey()]);

    $matchingTx = [
        'description' => 'Payment to Amazon Web Services EMEA',
        'payee' => 'AWS Inc',
        'amount' => 120.50,
        'account_id' => 7,
    ];
    $nonMatchingTx = [
        'text' => 'Coffee Shop',
        'payee' => 'Starbucks',
        'amount' => 5.0,
        'account_id' => 8,
    ];

    $matchedRules = $query->matching(1, $matchingTx);
    expect($matchedRules)->toHaveCount(1)
        ->and($matchedRules[0]->is($updated))->toBeTrue();

    expect($query->matching(1, $nonMatchingTx))->toBe([]);

    $testHit = $tester->handle($updated, $matchingTx);
    expect($testHit)->toBe([
        'matched' => true,
        'actions' => $updated->actions,
        'automation_mode' => 'suggest',
    ]);

    $testMiss = $tester->handle($updated, $nonMatchingTx);
    expect($testMiss)->toBe([
        'matched' => false,
        'actions' => [],
        'automation_mode' => 'suggest',
    ]);

    $history = $recorder->handle($updated, ' tx-999 ', true, ' applied ', ['category' => 'hosting'], 'system');
    expect($history)->toBeInstanceOf(BankRuleHistory::class)
        ->and($history->transaction_reference)->toBe('tx-999')
        ->and($history->outcome)->toBe('applied')
        ->and($history->matched)->toBeTrue()
        ->and($history->actions_applied)->toBe(['category' => 'hosting'])
        ->and($history->actor_reference)->toBe('system')
        ->and($history->created_at)->not->toBeNull()
        ->and($history->rule->is($updated))->toBeTrue()
        ->and($updated->histories()->count())->toBe(1);
});

it('validates bank rule inputs and prevents invalid conditions, splits, and duplicates', function (): void {
    $save = app(SaveBankRule::class);

    $base = [
        'team_id' => 2,
        'name' => 'Base Rule',
        'conditions' => ['text' => 'stripe'],
        'actions' => ['category' => 'fees'],
        'automation_mode' => 'suggest',
    ];

    expect(fn () => $save->handle([...$base, 'team_id' => 0]))->toThrow(InvalidBankRule::class)
        ->and(fn () => $save->handle([...$base, 'name' => '   ']))->toThrow(InvalidBankRule::class)
        ->and(fn () => $save->handle([...$base, 'automation_mode' => 'invalid']))->toThrow(InvalidBankRule::class)
        ->and(fn () => $save->handle([...$base, 'conditions' => []]))->toThrow(InvalidBankRule::class)
        ->and(fn () => $save->handle([...$base, 'actions' => []]))->toThrow(InvalidBankRule::class)
        ->and(fn () => $save->handle([...$base, 'conditions' => ['amount_min' => 200, 'amount_max' => 100]]))->toThrow(InvalidBankRule::class, 'minimum amount')
        ->and(fn () => $save->handle([...$base, 'actions' => ['splits' => [['percentage' => 50], ['percentage' => 30]]]]))->toThrow(InvalidBankRule::class, 'total 100');

    $first = $save->handle($base);
    $second = $save->handle([...$base, 'name' => 'Second Rule']);

    expect(fn () => $save->handle($base))->toThrow(InvalidBankRule::class, 'already exists')
        ->and(fn () => $save->handle([...$base, 'name' => 'Base Rule'], $second))->toThrow(InvalidBankRule::class, 'already exists');
});
