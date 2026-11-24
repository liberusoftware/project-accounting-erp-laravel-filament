<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Copilot\Actions\ConfirmCopilotRequest;
use Liberu\Accounting\Copilot\Actions\CreateCopilotRequest;
use Liberu\Accounting\Copilot\CopilotServiceProvider;
use Liberu\Accounting\Copilot\Models\CopilotRequest;
use Liberu\Accounting\Copilot\Queries\CopilotRequestQuery;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('registers the copilot service provider and runs migrations', function (): void {
    expect(app()->getProvider(CopilotServiceProvider::class))
        ->toBeInstanceOf(CopilotServiceProvider::class);

    expect(Schema::hasTable('accounting_copilot_requests'))->toBeTrue();
    expect(Schema::hasColumns('accounting_copilot_requests', [
        'id',
        'team_id',
        'actor_id',
        'kind',
        'prompt',
        'result',
        'status',
        'confirmation_key',
        'metadata',
    ]))->toBeTrue();
});

it('creates and confirms copilot requests and enforces confirmation key and status checks', function (): void {
    $create = new CreateCopilotRequest();
    $confirm = new ConfirmCopilotRequest();

    $request = $create->handle([
        'team_id' => 61,
        'actor_id' => 5,
        'kind' => 'journal_entry',
        'prompt' => 'Accrue August utilities',
        'result' => ['lines' => [['account' => '6200', 'debit' => 300]]],
        'confirmation_key' => 'confirm-key-1',
        'metadata' => ['source' => 'chat'],
    ]);

    expect($request)->toBeInstanceOf(CopilotRequest::class)
        ->and($request->status)->toBe('awaiting_confirmation')
        ->and($request->result)->toBe(['lines' => [['account' => '6200', 'debit' => 300]]])
        ->and($request->metadata)->toBe(['source' => 'chat']);

    try {
        $confirm->handle($request, 'wrong-key');
        $this->fail('Expected 422 HttpException for invalid confirmation key.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422)
            ->and($exception->getMessage())->toBe('Confirmation key is invalid.');
    }

    $confirmed = $confirm->handle($request, 'confirm-key-1');
    expect($confirmed->status)->toBe('confirmed');

    try {
        $confirm->handle($confirmed, 'confirm-key-1');
        $this->fail('Expected 409 HttpException when confirming an already confirmed request.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409)
            ->and($exception->getMessage())->toBe('This request is no longer awaiting confirmation.');
    }
});

it('queries copilot requests by team and optional kind filter', function (): void {
    $create = new CreateCopilotRequest();
    $query = new CopilotRequestQuery();

    expect($query->forTeam(777))->toBeEmpty();

    $this->travel(-2)->minutes();
    $journal = $create->handle([
        'team_id' => 62,
        'actor_id' => 1,
        'kind' => 'journal_entry',
        'prompt' => 'Post accrual',
        'confirmation_key' => 'k-1',
    ]);

    $this->travel(2)->minutes();
    $reconcile = $create->handle([
        'team_id' => 62,
        'actor_id' => 1,
        'kind' => 'reconciliation',
        'prompt' => 'Match bank feed',
        'confirmation_key' => 'k-2',
    ]);

    $create->handle([
        'team_id' => 63,
        'actor_id' => 2,
        'kind' => 'journal_entry',
        'prompt' => 'Other team',
        'confirmation_key' => 'k-3',
    ]);

    $allForTeam = $query->forTeam(62);
    expect($allForTeam)->toHaveCount(2)
        ->and($allForTeam->pluck('id')->all())->toBe([$reconcile->id, $journal->id]);

    $filteredByKind = $query->forTeam(62, 'journal_entry');
    expect($filteredByKind)->toHaveCount(1)
        ->and($filteredByKind->first()->id)->toBe($journal->id);
});
