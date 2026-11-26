<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\SpreadsheetMigration\Enums\MigrationMode;
use Liberu\Accounting\SpreadsheetMigration\Enums\MigrationStatus;
use Liberu\Accounting\SpreadsheetMigration\Models\MigrationRun;
use Liberu\Accounting\SpreadsheetMigrationApi\SpreadsheetMigrationApiServiceProvider;

it('covers accounting-spreadsheet-migration-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(SpreadsheetMigrationApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(SpreadsheetMigrationApiServiceProvider::class);

    $templateResponse = $this->postJson('/api/v1/accounting/spreadsheet-migration/templates', [
        'name' => 'Opening Balances CSV',
        'entity' => 'opening_balance',
        'mapping' => ['col_a' => 'account_code', 'col_b' => 'amount'],
    ]);
    $templateResponse->assertSuccessful();
    $templateId = $templateResponse->json('id');

    $this->getJson('/api/v1/accounting/spreadsheet-migration/templates')->assertOk();

    $rows = [
        ['col_a' => '1000', 'col_b' => 500.00],
    ];
    MigrationRun::query()->create([
        'template_id' => $templateId,
        'mode' => MigrationMode::Opening,
        'status' => MigrationStatus::Draft,
        'source_hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        'row_count' => count($rows),
        'source_total' => 500.00,
        'target_total' => 500.00,
    ]);

    $runResponse = $this->postJson("/api/v1/accounting/spreadsheet-migration/templates/{$templateId}/runs", [
        'mode' => 'opening',
        'rows' => $rows,
        'source_total' => 500.00,
        'target_total' => 500.00,
    ]);
    $runResponse->assertSuccessful();
    $runId = $runResponse->json('data.id');

    $this->postJson("/api/v1/accounting/spreadsheet-migration/runs/{$runId}/validate")->assertOk();
});
