<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\GeneralLedger\Models\JournalEntry;
use Liberu\Accounting\GeneralLedger\Models\RecurringJournal;
use Liberu\Accounting\GeneralLedgerApi\GeneralLedgerApiServiceProvider;
use Liberu\Accounting\GeneralLedgerApi\Policies\GeneralLedgerPolicy;

it('covers accounting-general-ledger-api provider, policy, controllers, and resource', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(GeneralLedgerApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(GeneralLedgerApiServiceProvider::class);

    $policy = new GeneralLedgerPolicy();
    $allowUser = new class() extends User
    {
        public int $current_team_id = 1;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $denyUser = new class() extends User
    {
        public int $current_team_id = 2;

        public function tokenCan(string $a): bool
        {
            return false;
        }
    };
    $record = (new JournalEntry())->forceFill(['team_id' => 1, 'legal_entity_id' => 1]);
    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $record))->toBeTrue();
    expect($policy->view($denyUser, $record))->toBeFalse();
    expect($policy->view(null, $record))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeTrue();
    expect($policy->update($denyUser, $record))->toBeFalse();
    expect($policy->update(null, $record))->toBeFalse();
    expect($policy->delete($allowUser, $record))->toBeTrue();
    expect($policy->delete($denyUser, $record))->toBeFalse();
    expect($policy->delete(null, $record))->toBeFalse();

    // GeneralLedgerController (v1/accounting/general-ledger)
    $legalEntityId = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'GL Corp',
        'registration_number' => 'GL-001',
        'currency_code' => 'USD',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bookId = DB::table('accounting_books')->insertGetId([
        'legal_entity_id' => $legalEntityId,
        'name' => 'Primary Book',
        'code' => 'PRIM',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $debitAccount = Account::create([
        'legal_entity_id' => $legalEntityId,
        'code' => '1000',
        'name' => 'Cash',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'allow_manual_entry' => true,
        'is_active' => true,
    ]);
    $creditAccount = Account::create([
        'legal_entity_id' => $legalEntityId,
        'code' => '4000',
        'name' => 'Revenue',
        'type' => 'revenue',
        'normal_balance' => 'credit',
        'allow_manual_entry' => true,
        'is_active' => true,
    ]);

    $balancedLines = [
        ['account_id' => $debitAccount->getKey(), 'debit' => 100, 'credit' => 0, 'description' => 'Cash in'],
        ['account_id' => $creditAccount->getKey(), 'debit' => 0, 'credit' => 100, 'description' => 'Sales'],
    ];
    $unbalancedLines = [
        ['account_id' => $debitAccount->getKey(), 'debit' => 100, 'credit' => 0],
        ['account_id' => $creditAccount->getKey(), 'debit' => 0, 'credit' => 50],
    ];

    $glBase = '/api/v1/accounting/general-ledger';

    $storeRes = $this->postJson($glBase, [
        'book_id' => $bookId,
        'entry_date' => '2026-01-15',
        'description' => 'Cash sale',
        'lines' => $balancedLines,
    ]);
    expect($storeRes->status())->toBe(201);
    $journalId = $storeRes->json('data.id');

    // Unbalanced store -> catch InvalidJournal -> 422
    expect($this->postJson($glBase, [
        'book_id' => $bookId,
        'entry_date' => '2026-01-15',
        'lines' => $unbalancedLines,
    ])->status())->toBe(422);

    // Typed journal + InvalidJournal branch
    $typedRes = $this->postJson("{$glBase}/accruals", [
        'book_id' => $bookId,
        'entry_date' => '2026-01-20',
        'description' => 'Monthly accrual',
        'lines' => $balancedLines,
    ]);
    expect($typedRes->status())->toBeLessThan(500);
    $draftJournalId = $typedRes->json('data.id');

    expect($this->postJson("{$glBase}/corrections", [
        'book_id' => $bookId,
        'entry_date' => '2026-01-20',
        'lines' => $unbalancedLines,
    ])->status())->toBe(422);

    expect($this->getJson("{$glBase}?book_id={$bookId}")->status())->toBe(200);
    expect($this->getJson("{$glBase}/{$journalId}")->status())->toBe(200);
    expect($this->getJson("{$glBase}/balances?book_id={$bookId}")->status())->toBe(200);

    // Reverse draft -> catch InvalidJournal -> 422
    expect($this->postJson("{$glBase}/{$draftJournalId}/reverse", ['description' => 'Too early'])->status())->toBe(422);

    // Post valid journal -> 200; post again -> catch InvalidJournal -> 422
    expect($this->postJson("{$glBase}/{$journalId}/post")->status())->toBe(200);
    expect($this->postJson("{$glBase}/{$journalId}/post")->status())->toBe(422);

    // Reverse posted journal -> 200
    expect($this->postJson("{$glBase}/{$journalId}/reverse", ['description' => 'Reversal'])->status())->toBe(200);

    // Delete posted -> 409; delete draft -> 204
    expect($this->deleteJson("{$glBase}/{$journalId}")->status())->toBe(409);
    expect($this->deleteJson("{$glBase}/{$draftJournalId}")->status())->toBe(204);

    // Recurring journal save & generate + inactive InvalidJournal branch
    $recRes = $this->postJson("{$glBase}/recurring", [
        'book_id' => $bookId,
        'name' => 'Monthly Rent',
        'frequency' => 'monthly',
        'next_run_on' => '2026-02-01',
        'lines' => $balancedLines,
    ]);
    expect($recRes->status())->toBe(201);
    $recId = $recRes->json('data.id');

    expect($this->postJson("{$glBase}/recurring/{$recId}/generate")->status())->toBeLessThan(500);

    RecurringJournal::whereKey($recId)->update(['is_active' => false]);
    expect($this->postJson("{$glBase}/recurring/{$recId}/generate")->status())->toBe(422);

    // JournalEntryController (api/v1/journal-entries)
    $erpAcc1 = DB::table('accounts')->insertGetId([
        'team_id' => 1,
        'user_id' => $user->id,
        'account_number' => 1010,
        'account_name' => 'Checking',
        'account_type' => 'asset',
        'balance' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $erpAcc2 = DB::table('accounts')->insertGetId([
        'team_id' => 1,
        'user_id' => $user->id,
        'account_number' => 4010,
        'account_name' => 'Sales',
        'account_type' => 'revenue',
        'balance' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $erpEntryRes = $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-01-15',
        'entry_type' => 'general',
        'memo' => 'Balanced ERP entry',
        'lines' => [
            ['account_id' => $erpAcc1, 'debit_amount' => 200, 'credit_amount' => 0, 'description' => 'Dr'],
            ['account_id' => $erpAcc2, 'debit_amount' => 0, 'credit_amount' => 200, 'description' => 'Cr'],
        ],
    ]);
    expect($erpEntryRes->status())->toBe(201);
    $erpEntryId = $erpEntryRes->json('id');

    // Unbalanced ERP entry -> 422
    expect($this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-01-15',
        'lines' => [
            ['account_id' => $erpAcc1, 'debit_amount' => 200, 'credit_amount' => 0],
            ['account_id' => $erpAcc2, 'debit_amount' => 0, 'credit_amount' => 100],
        ],
    ])->status())->toBe(422);

    expect($this->getJson('/api/v1/journal-entries')->status())->toBe(200);
    expect($this->getJson("/api/v1/journal-entries/{$erpEntryId}")->status())->toBe(200);
    expect($this->deleteJson("/api/v1/journal-entries/{$erpEntryId}")->status())->toBe(200);

    // ErpGeneralLedgerController
    expect($this->getJson('/api/v1/general-ledger/trial-balance')->status())->toBe(200);
    expect($this->getJson('/api/v1/general-ledger/balances')->status())->toBe(200);
});
