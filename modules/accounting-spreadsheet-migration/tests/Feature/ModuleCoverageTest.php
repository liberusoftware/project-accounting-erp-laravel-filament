<?php

declare(strict_types=1);

use Liberu\Accounting\SpreadsheetMigration\Actions\CreateMigrationRun;
use Liberu\Accounting\SpreadsheetMigration\Actions\CreateMigrationTemplate;
use Liberu\Accounting\SpreadsheetMigration\Actions\ValidateMigration;
use Liberu\Accounting\SpreadsheetMigration\Enums\MigrationMode;
use Liberu\Accounting\SpreadsheetMigration\Enums\MigrationStatus;
use Liberu\Accounting\SpreadsheetMigration\Exceptions\InvalidMigration;
use Liberu\Accounting\SpreadsheetMigration\Models\MigrationRun;
use Liberu\Accounting\SpreadsheetMigration\Models\MigrationTemplate;
use Liberu\Accounting\SpreadsheetMigration\SpreadsheetMigrationServiceProvider;

it('boots spreadsheet migration service provider and covers enums and exception', function (): void {
    $provider = $this->app->register(SpreadsheetMigrationServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(SpreadsheetMigrationServiceProvider::class)
        ->and(array_column(MigrationMode::cases(), 'value'))->toBe(['opening', 'outstanding', 'detail'])
        ->and(array_column(MigrationStatus::cases(), 'value'))->toBe(['draft', 'validated', 'reconciled', 'failed'])
        ->and((new InvalidMigration('Migration error'))->getMessage())->toBe('Migration error');
});

it('creates migration templates, runs, and validates balanced migrations', function (): void {
    $createTemplate = app(CreateMigrationTemplate::class);
    $createRun = app(CreateMigrationRun::class);
    $validate = app(ValidateMigration::class);

    $template = $createTemplate->handle([
        'name' => 'Opening Trial Balance',
        'entity' => 'journal_entries',
        'mapping' => ['account' => 'A', 'debit' => 'B', 'credit' => 'C'],
        'metadata' => ['source' => 'excel'],
    ]);

    expect($template)->toBeInstanceOf(MigrationTemplate::class)
        ->and($template->mapping)->toBe(['account' => 'A', 'debit' => 'B', 'credit' => 'C'])
        ->and($template->metadata)->toBe(['source' => 'excel']);

    $rows = [
        ['account' => '1000', 'debit' => 500.00, 'credit' => 0.00],
        ['account' => '2000', 'debit' => 0.00, 'credit' => 500.00],
    ];

    $run = $createRun->handle($template, [
        'mode' => MigrationMode::Opening,
        'rows' => $rows,
        'source_total' => 500.00,
        'target_total' => 500.00,
        'metadata' => ['batch' => 1],
    ]);

    expect($run)->toBeInstanceOf(MigrationRun::class)
        ->and($run->mode)->toBe(MigrationMode::Opening)
        ->and($run->status)->toBe(MigrationStatus::Draft)
        ->and($run->row_count)->toBe(2)
        ->and((float) $run->source_total)->toBe(500.0)
        ->and((float) $run->target_total)->toBe(500.0)
        ->and($run->metadata)->toBe(['batch' => 1])
        ->and($run->template->is($template))->toBeTrue();

    // Idempotent firstOrCreate for identical template + rows
    $duplicateRun = $createRun->handle($template, [
        'mode' => MigrationMode::Detail,
        'rows' => $rows,
        'source_total' => 999.00,
        'target_total' => 999.00,
    ]);

    expect($duplicateRun->is($run))->toBeTrue();

    $validated = $validate->handle($run);
    expect($validated->status)->toBe(MigrationStatus::Validated)
        ->and($validated->errors)->toBeNull();

    // Default mode and empty rows path
    $emptyRun = $createRun->handle($template, []);
    expect($emptyRun->mode)->toBe(MigrationMode::Detail)
        ->and($emptyRun->row_count)->toBe(0)
        ->and((float) $emptyRun->source_total)->toBe(0.0)
        ->and((float) $emptyRun->target_total)->toBe(0.0);
});

it('enforces validation rules for migration templates and runs', function (): void {
    $createTemplate = app(CreateMigrationTemplate::class);
    $createRun = app(CreateMigrationRun::class);
    $validate = app(ValidateMigration::class);

    $validTemplate = [
        'name' => 'Vendor Balances',
        'entity' => 'supplier_bills',
        'mapping' => ['supplier' => 'A', 'amount' => 'B'],
    ];

    foreach (['name', 'entity', 'mapping'] as $required) {
        expect(fn () => $createTemplate->handle(array_diff_key($validTemplate, [$required => true])))
            ->toThrow(InvalidMigration::class, "Template field [{$required}] is required.");
    }

    expect(fn () => $createTemplate->handle([...$validTemplate, 'mapping' => 'not-an-array']))
        ->toThrow(InvalidMigration::class, 'Template mapping must be an array.');

    $template = $createTemplate->handle($validTemplate);

    $zeroRowRun = $createRun->handle($template, [
        'rows' => [],
        'source_total' => 0,
        'target_total' => 0,
    ]);

    expect(fn () => $validate->handle($zeroRowRun))
        ->toThrow(InvalidMigration::class, 'A migration must contain at least one row.');

    $unbalancedRun = $createRun->handle($template, [
        'rows' => [['supplier' => 'Acme', 'amount' => 100]],
        'source_total' => 100.00,
        'target_total' => 95.00,
    ]);

    expect(fn () => $validate->handle($unbalancedRun))
        ->toThrow(InvalidMigration::class, 'Migration totals do not balance.');
});
