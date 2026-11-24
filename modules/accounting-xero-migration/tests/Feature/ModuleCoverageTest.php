<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\XeroMigration\Actions\ConnectXeroTenant;
use Liberu\Accounting\XeroMigration\Actions\RecordMigration;
use Liberu\Accounting\XeroMigration\Enums\MigrationRecordStatus;
use Liberu\Accounting\XeroMigration\Enums\XeroConnectionStatus;
use Liberu\Accounting\XeroMigration\Exceptions\InvalidXeroMigration;
use Liberu\Accounting\XeroMigration\Models\XeroConnection;
use Liberu\Accounting\XeroMigration\Models\XeroMigrationRecord;
use Liberu\Accounting\XeroMigration\XeroMigrationServiceProvider;

it('boots the xero migration service provider and loads migrations', function (): void {
    $provider = $this->app->register(XeroMigrationServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(XeroMigrationServiceProvider::class)
        ->and(Schema::hasTable('accounting_xero_connections'))->toBeTrue()
        ->and(Schema::hasTable('accounting_xero_migration_records'))->toBeTrue();
});

it('exposes xero migration enums and exception', function (): void {
    expect(XeroConnectionStatus::cases())->toHaveCount(3)
        ->and(XeroConnectionStatus::Active->value)->toBe('active')
        ->and(XeroConnectionStatus::Expired->value)->toBe('expired')
        ->and(XeroConnectionStatus::Revoked->value)->toBe('revoked')
        ->and(MigrationRecordStatus::cases())->toHaveCount(3)
        ->and(MigrationRecordStatus::Pending->value)->toBe('pending')
        ->and(MigrationRecordStatus::Migrated->value)->toBe('migrated')
        ->and(MigrationRecordStatus::Failed->value)->toBe('failed');

    $exception = new InvalidXeroMigration('Invalid migration state');
    expect($exception)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($exception->getMessage())->toBe('Invalid migration state');
});

it('connects and updates xero tenants and enforces validation', function (): void {
    $action = new ConnectXeroTenant();

    expect(fn () => $action->handle(['team_id' => 1, 'tenant_ref' => '', 'access_token' => 'tok']))
        ->toThrow(InvalidXeroMigration::class, 'A Xero tenant reference and access token are required.');

    expect(fn () => $action->handle(['team_id' => 1, 'tenant_ref' => 'xero-org-1', 'access_token' => '']))
        ->toThrow(InvalidXeroMigration::class, 'A Xero tenant reference and access token are required.');

    $connection = $action->handle([
        'team_id' => 1,
        'tenant_ref' => 'xero-org-1',
        'access_token' => 'secret-access-token',
        'refresh_token' => 'secret-refresh-token',
        'token_expires_at' => '2026-12-01 00:00:00',
        'last_synced_at' => '2026-11-20 12:00:00',
        'metadata' => ['org_name' => 'Acme Ltd'],
    ]);

    expect($connection)->toBeInstanceOf(XeroConnection::class)
        ->and($connection->status)->toBe(XeroConnectionStatus::Active)
        ->and($connection->access_token)->toBe('secret-access-token')
        ->and($connection->refresh_token)->toBe('secret-refresh-token')
        ->and($connection->token_expires_at)->toBeInstanceOf(Carbon::class)
        ->and($connection->last_synced_at)->toBeInstanceOf(Carbon::class)
        ->and($connection->metadata)->toBe(['org_name' => 'Acme Ltd'])
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);

    $updated = $action->handle([
        'team_id' => 1,
        'tenant_ref' => 'xero-org-1',
        'access_token' => 'rotated-access-token',
    ]);

    expect($updated->id)->toBe($connection->id)
        ->and($updated->access_token)->toBe('rotated-access-token');
});

it('records migration items, updates existing records, and handles failures', function (): void {
    $connection = (new ConnectXeroTenant())->handle([
        'team_id' => 7,
        'tenant_ref' => 'xero-org-7',
        'access_token' => 'token-7',
    ]);

    $action = new RecordMigration();

    expect(fn () => $action->handle($connection, ['source_type' => '', 'source_id' => 'inv-1']))
        ->toThrow(InvalidXeroMigration::class, 'A source type and source identifier are required.');

    expect(fn () => $action->handle($connection, ['source_type' => 'invoice', 'source_id' => '']))
        ->toThrow(InvalidXeroMigration::class, 'A source type and source identifier are required.');

    $record = $action->handle($connection, [
        'source_type' => 'invoice',
        'source_id' => 'xero-inv-100',
        'target_type' => 'accounting_invoice',
        'target_id' => '42',
        'payload' => ['total' => 120.50],
    ]);

    expect($record)->toBeInstanceOf(XeroMigrationRecord::class)
        ->and($record->team_id)->toBe(7)
        ->and($record->status)->toBe(MigrationRecordStatus::Migrated)
        ->and($record->payload)->toBe(['total' => 120.50])
        ->and($record->connection->is($connection))->toBeTrue();

    $failed = $action->handle($connection, [
        'source_type' => 'contact',
        'source_id' => 'xero-con-200',
        'status' => MigrationRecordStatus::Failed,
        'error' => 'Missing currency code',
    ]);

    expect($failed->status)->toBe(MigrationRecordStatus::Failed)
        ->and($failed->error)->toBe('Missing currency code');

    $retried = $action->handle($connection, [
        'source_type' => 'contact',
        'source_id' => 'xero-con-200',
        'target_type' => 'customer',
        'target_id' => '99',
        'error' => null,
    ]);

    expect($retried->id)->toBe($failed->id)
        ->and($retried->status)->toBe(MigrationRecordStatus::Migrated)
        ->and($connection->migrationRecords()->count())->toBe(2);
});
