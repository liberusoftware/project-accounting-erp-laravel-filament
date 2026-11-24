<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\FinancialMasterData\Actions\ArchiveParty;
use Liberu\Accounting\FinancialMasterData\Actions\MergeDuplicateParties;
use Liberu\Accounting\FinancialMasterData\Actions\SaveParty;
use Liberu\Accounting\FinancialMasterData\Actions\SaveReferenceData;
use Liberu\Accounting\FinancialMasterData\Enums\PartyType;
use Liberu\Accounting\FinancialMasterData\Enums\RecordStatus;
use Liberu\Accounting\FinancialMasterData\Events\MasterRecordArchived;
use Liberu\Accounting\FinancialMasterData\Events\MasterRecordCreated;
use Liberu\Accounting\FinancialMasterData\Events\MasterRecordsMerged;
use Liberu\Accounting\FinancialMasterData\Exceptions\DuplicateMasterRecord;
use Liberu\Accounting\FinancialMasterData\FinancialMasterDataServiceProvider;
use Liberu\Accounting\FinancialMasterData\Models\Address;
use Liberu\Accounting\FinancialMasterData\Models\BankDetailReference;
use Liberu\Accounting\FinancialMasterData\Models\ItemService;
use Liberu\Accounting\FinancialMasterData\Models\Party;
use Liberu\Accounting\FinancialMasterData\Models\PaymentTerm;
use Liberu\Accounting\FinancialMasterData\Models\TaxProfile;
use Liberu\Accounting\FinancialMasterData\Queries\FindDuplicateParties;

it('registers and boots the financial master data service provider and migrations', function (): void {
    $provider = app()->getProvider(FinancialMasterDataServiceProvider::class)
        ?? new FinancialMasterDataServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(FinancialMasterDataServiceProvider::class)
        ->and(Schema::hasTable('accounting_master_payment_terms'))->toBeTrue()
        ->and(Schema::hasTable('accounting_master_parties'))->toBeTrue()
        ->and(Schema::hasTable('accounting_master_tax_profiles'))->toBeTrue()
        ->and(Schema::hasTable('accounting_master_items_services'))->toBeTrue()
        ->and(Schema::hasTable('accounting_master_addresses'))->toBeTrue()
        ->and(Schema::hasTable('accounting_master_bank_detail_references'))->toBeTrue();
});

it('exposes financial master data enums, exceptions, and events', function (): void {
    expect(array_map(static fn (PartyType $t): string => $t->value, PartyType::cases()))
        ->toBe(['customer', 'supplier'])
        ->and(array_map(static fn (RecordStatus $s): string => $s->value, RecordStatus::cases()))
        ->toBe(['active', 'inactive', 'archived']);

    $exception = new DuplicateMasterRecord('Duplicate found.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Duplicate found.');

    $entityId = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'Master Events Ltd',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $p1 = Party::create([
        'legal_entity_id' => $entityId,
        'type' => PartyType::Customer,
        'name' => 'Alpha Corp',
    ]);
    $p2 = Party::create([
        'legal_entity_id' => $entityId,
        'type' => PartyType::Customer,
        'name' => 'Beta Corp',
    ]);

    $createdEvent = new MasterRecordCreated($p1);
    $archivedEvent = new MasterRecordArchived($p1);
    $mergedEvent = new MasterRecordsMerged($p1, $p2);

    expect($createdEvent)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($createdEvent->record->is($p1))->toBeTrue()
        ->and($archivedEvent)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($archivedEvent->record->is($p1))->toBeTrue()
        ->and($mergedEvent)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($mergedEvent->survivor->is($p1))->toBeTrue()
        ->and($mergedEvent->duplicate->is($p2))->toBeTrue();
});

it('persists models with casts, defaults, scopes, and relationships', function (): void {
    $entityId = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'Master Models Ltd',
        'currency_code' => 'USD',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $term = PaymentTerm::create([
        'legal_entity_id' => $entityId,
        'code' => 'NET30',
        'name' => 'Net 30 Days',
        'days' => 30,
        'metadata' => ['tier' => 'standard'],
    ]);

    expect($term->days)->toBe(30)
        ->and($term->status)->toBe('active')
        ->and($term->metadata)->toBe(['tier' => 'standard']);

    $tax = TaxProfile::create([
        'legal_entity_id' => $entityId,
        'code' => 'VAT20',
        'name' => 'UK VAT 20%',
        'rate' => 20.0000,
        'metadata' => ['region' => 'GB'],
    ]);

    expect((float) $tax->rate)->toBe(20.0)
        ->and($tax->inclusive)->toBeFalse()
        ->and($tax->recoverable)->toBeTrue()
        ->and($tax->status)->toBe(RecordStatus::Active)
        ->and($tax->metadata)->toBe(['region' => 'GB']);

    $item = ItemService::create([
        'legal_entity_id' => $entityId,
        'sku' => 'SVC-001',
        'name' => 'Consulting Hour',
        'description' => 'Advisory services',
        'unit' => 'hour',
        'sales_price' => 150.50,
        'purchase_price' => 75.25,
        'tax_profile_id' => $tax->id,
        'metadata' => ['billable' => true],
    ]);

    expect($item->kind)->toBe('service')
        ->and($item->status)->toBe(RecordStatus::Active)
        ->and((float) $item->sales_price)->toBe(150.5)
        ->and((float) $item->purchase_price)->toBe(75.25)
        ->and($item->metadata)->toBe(['billable' => true]);

    $activeParty = Party::create([
        'legal_entity_id' => $entityId,
        'type' => PartyType::Customer,
        'reference' => 'CUST-01',
        'name' => 'Acme Holdings',
        'email' => 'billing@acme.test',
        'phone' => '+44123456789',
        'tax_identifier' => 'GB123456789',
        'payment_term_id' => $term->id,
        'credit_limit' => 5000.00,
        'metadata' => ['segment' => 'enterprise'],
    ]);

    Party::create([
        'legal_entity_id' => $entityId,
        'type' => PartyType::Supplier,
        'name' => 'Archived Supplier',
        'status' => RecordStatus::Archived,
    ]);

    expect($activeParty->type)->toBe(PartyType::Customer)
        ->and($activeParty->status)->toBe(RecordStatus::Active)
        ->and((float) $activeParty->credit_limit)->toBe(5000.0)
        ->and($activeParty->metadata)->toBe(['segment' => 'enterprise'])
        ->and(Party::query()->where('legal_entity_id', $entityId)->active()->count())->toBe(1);

    $address = Address::create([
        'party_id' => $activeParty->id,
        'kind' => 'billing',
        'line_one' => '10 Downing Street',
        'city' => 'London',
        'postal_code' => 'SW1A 2AA',
        'country_code' => 'GB',
        'is_primary' => true,
        'metadata' => ['verified' => true],
    ]);

    $bank = BankDetailReference::create([
        'party_id' => $activeParty->id,
        'label' => 'Primary GBP',
        'account_name' => 'Acme Holdings Ltd',
        'bank_name' => 'Barclays',
        'country_code' => 'GB',
        'currency_code' => 'GBP',
        'masked_account' => '****1234',
        'credential_reference' => 'vault://bank/acme-1',
        'is_primary' => true,
        'metadata' => ['verified' => true],
    ]);

    expect($address->is_primary)->toBeTrue()
        ->and($address->metadata)->toBe(['verified' => true])
        ->and($address->party->is($activeParty))->toBeTrue()
        ->and($bank->is_primary)->toBeTrue()
        ->and($bank->status)->toBe('active')
        ->and($bank->metadata)->toBe(['verified' => true])
        ->and($bank->party->is($activeParty))->toBeTrue()
        ->and($activeParty->addresses)->toHaveCount(1)
        ->and($activeParty->bankDetails)->toHaveCount(1);
});

it('saves, updates, validates duplicates, and archives parties with events', function (): void {
    Event::fake([MasterRecordCreated::class, MasterRecordArchived::class]);

    $entityId = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'SaveParty Entity',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $saveParty = app(SaveParty::class);
    $archiveParty = app(ArchiveParty::class);

    // Validation failures for missing required attributes
    expect(fn () => $saveParty->handle(['type' => 'customer', 'name' => 'Missing Entity']))
        ->toThrow(DuplicateMasterRecord::class, 'Legal entity, party type, and name are required.');
    expect(fn () => $saveParty->handle(['legal_entity_id' => $entityId, 'name' => 'Missing Type']))
        ->toThrow(DuplicateMasterRecord::class, 'Legal entity, party type, and name are required.');
    expect(fn () => $saveParty->handle(['legal_entity_id' => $entityId, 'type' => 'customer', 'name' => '   ']))
        ->toThrow(DuplicateMasterRecord::class, 'Legal entity, party type, and name are required.');

    // Create party
    $party = $saveParty->handle([
        'legal_entity_id' => $entityId,
        'type' => PartyType::Customer->value,
        'name' => 'Global Corp',
        'email' => 'ap@global.test',
    ]);

    expect($party->exists)->toBeTrue()
        ->and($party->name)->toBe('Global Corp')
        ->and($party->status)->toBe(RecordStatus::Active);
    Event::assertDispatched(MasterRecordCreated::class, fn (MasterRecordCreated $e): bool => $e->record->is($party));

    // Duplicate by case-insensitive name
    expect(fn () => $saveParty->handle([
        'legal_entity_id' => $entityId,
        'type' => PartyType::Customer->value,
        'name' => '  global corp  ',
    ]))->toThrow(DuplicateMasterRecord::class, 'A matching customer or supplier already exists for this legal entity.');

    // Duplicate by case-insensitive email
    expect(fn () => $saveParty->handle([
        'legal_entity_id' => $entityId,
        'type' => PartyType::Customer->value,
        'name' => 'Different Name',
        'email' => 'AP@GLOBAL.TEST',
    ]))->toThrow(DuplicateMasterRecord::class, 'A matching customer or supplier already exists for this legal entity.');

    // Update existing party without triggering duplicate against self or firing MasterRecordCreated again
    $updated = $saveParty->handle(['phone' => '+4400001111'], $party);
    expect($updated->phone)->toBe('+4400001111');
    Event::assertDispatchedTimes(MasterRecordCreated::class, 1);

    // Same name allowed for a different party type
    $supplier = $saveParty->handle([
        'legal_entity_id' => $entityId,
        'type' => PartyType::Supplier->value,
        'name' => 'Global Corp',
    ]);
    expect($supplier->type)->toBe(PartyType::Supplier);

    // Archive party
    $archived = $archiveParty->handle($party);
    expect($archived->status)->toBe(RecordStatus::Archived);
    Event::assertDispatched(MasterRecordArchived::class, fn (MasterRecordArchived $e): bool => $e->record->is($archived));

    // Archiving an already archived party throws
    expect(fn () => $archiveParty->handle($archived))
        ->toThrow(DuplicateMasterRecord::class, 'The party is already archived.');

    // Archived party does not block creating a new active party with same name
    $recreated = $saveParty->handle([
        'legal_entity_id' => $entityId,
        'type' => PartyType::Customer->value,
        'name' => 'Global Corp',
    ]);
    expect($recreated->id)->not->toBe($archived->id);
});

it('saves and updates reference data and prevents duplicate code or sku', function (): void {
    Event::fake([MasterRecordCreated::class]);

    $entityId = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'Reference Entity',
        'currency_code' => 'EUR',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $saveRef = app(SaveReferenceData::class);

    $term = $saveRef->handle(PaymentTerm::class, [
        'legal_entity_id' => $entityId,
        'code' => 'NET15',
        'name' => 'Net 15',
        'days' => 15,
    ]);
    expect($term)->toBeInstanceOf(PaymentTerm::class)
        ->and($term->code)->toBe('NET15');
    Event::assertDispatched(MasterRecordCreated::class, fn (MasterRecordCreated $e): bool => $e->record->is($term));

    // Duplicate code throws
    expect(fn () => $saveRef->handle(PaymentTerm::class, [
        'legal_entity_id' => $entityId,
        'code' => 'NET15',
        'name' => 'Duplicate Net 15',
        'days' => 15,
    ]))->toThrow(DuplicateMasterRecord::class, 'The reference is already in use for this legal entity.');

    // Updating existing record succeeds without re-dispatching MasterRecordCreated
    $updatedTerm = $saveRef->handle(PaymentTerm::class, ['name' => 'Net 15 Updated'], $term);
    expect($updatedTerm->name)->toBe('Net 15 Updated');
    Event::assertDispatchedTimes(MasterRecordCreated::class, 1);

    // ItemService with SKU uniqueness check
    $item = $saveRef->handle(ItemService::class, [
        'legal_entity_id' => $entityId,
        'sku' => 'ITEM-100',
        'name' => 'Widget',
    ]);
    expect($item)->toBeInstanceOf(ItemService::class)
        ->and($item->sku)->toBe('ITEM-100');

    expect(fn () => $saveRef->handle(ItemService::class, [
        'legal_entity_id' => $entityId,
        'sku' => 'ITEM-100',
        'name' => 'Duplicate Widget',
    ]))->toThrow(DuplicateMasterRecord::class, 'The reference is already in use for this legal entity.');
});

it('finds duplicate parties and merges duplicates into the survivor', function (): void {
    $entity1 = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'Merge Entity One',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $entity2 = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'Merge Entity Two',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $findDuplicates = app(FindDuplicateParties::class);
    $mergeAction = app(MergeDuplicateParties::class);

    // Empty case
    expect($findDuplicates->handle($entity1))->toBeEmpty();

    $survivor = Party::create([
        'legal_entity_id' => $entity1,
        'type' => PartyType::Customer,
        'name' => 'Northwind Traders',
        'email' => 'info@northwind.test',
    ]);
    $duplicate = Party::create([
        'legal_entity_id' => $entity1,
        'type' => PartyType::Customer,
        'name' => 'Northwind Traders Ltd',
        'email' => ' INFO@NORTHWIND.TEST ',
        'metadata' => ['source' => 'import'],
    ]);
    // Duplicate by name (no email)
    $nameDup1 = Party::create([
        'legal_entity_id' => $entity1,
        'type' => PartyType::Supplier,
        'name' => 'Paper Co',
    ]);
    $nameDup2 = Party::create([
        'legal_entity_id' => $entity1,
        'type' => PartyType::Supplier,
        'name' => 'paper co',
    ]);
    // Unique party
    Party::create([
        'legal_entity_id' => $entity1,
        'type' => PartyType::Customer,
        'name' => 'Unique Client',
        'email' => 'unique@client.test',
    ]);
    // Different entity party
    $otherEntityParty = Party::create([
        'legal_entity_id' => $entity2,
        'type' => PartyType::Customer,
        'name' => 'Northwind Traders',
    ]);

    // Query all types -> 2 duplicate groups
    $allGroups = $findDuplicates->handle($entity1);
    expect($allGroups)->toHaveCount(2);

    // Filter by type -> 1 duplicate group each
    expect($findDuplicates->handle($entity1, PartyType::Customer->value))->toHaveCount(1)
        ->and($findDuplicates->handle($entity1, PartyType::Supplier->value))->toHaveCount(1);

    // Attach address & bank detail to duplicate to verify reassignment
    $addr = Address::create([
        'party_id' => $duplicate->id,
        'line_one' => '1 High St',
        'country_code' => 'GB',
    ]);
    $bank = BankDetailReference::create([
        'party_id' => $duplicate->id,
        'label' => 'Main',
        'credential_reference' => 'vault://dup/1',
    ]);

    // Validation errors on MergeDuplicateParties
    expect(fn () => $mergeAction->handle($survivor, $survivor))
        ->toThrow(DuplicateMasterRecord::class, 'Only duplicate parties from the same legal entity and type can be merged.');
    expect(fn () => $mergeAction->handle($survivor, $otherEntityParty))
        ->toThrow(DuplicateMasterRecord::class, 'Only duplicate parties from the same legal entity and type can be merged.');
    expect(fn () => $mergeAction->handle($survivor, $nameDup1))
        ->toThrow(DuplicateMasterRecord::class, 'Only duplicate parties from the same legal entity and type can be merged.');

    // Happy path merge
    $merged = $mergeAction->handle($survivor, $duplicate);
    $duplicate->refresh();
    $addr->refresh();
    $bank->refresh();

    expect($merged->is($survivor))->toBeTrue()
        ->and($addr->party_id)->toBe($survivor->id)
        ->and($bank->party_id)->toBe($survivor->id)
        ->and($duplicate->status)->toBe(RecordStatus::Archived)
        ->and($duplicate->metadata['source'])->toBe('import')
        ->and($duplicate->metadata['merged_into'])->toBe($survivor->id)
        ->and($duplicate->metadata)->toHaveKey('merged_at');

    // Archived parties cannot be merged
    expect(fn () => $mergeAction->handle($survivor, $duplicate))
        ->toThrow(DuplicateMasterRecord::class, 'Archived parties cannot participate in a merge.');
    expect(fn () => $mergeAction->handle($duplicate, $survivor))
        ->toThrow(DuplicateMasterRecord::class, 'Archived parties cannot participate in a merge.');

    // After merge, customer duplicate group is resolved
    expect($findDuplicates->handle($entity1, PartyType::Customer->value))->toBeEmpty()
        ->and($nameDup2->exists)->toBeTrue();
});
