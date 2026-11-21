<?php

use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\Erp\Actions\SaveBillOfMaterials;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\InventoryItem;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\InventoryItems\Pages\CreateInventoryItem;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\InventoryItems\Pages\ListInventoryItems;
use Liberu\Foundation\Organizations\Models\Team;
use Livewire\Livewire;

function bomItem(Team $team, string $reference): InventoryItem
{
    return InventoryItem::factory()->create(['team_id' => $team->id, 'name' => $reference]);
}

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => $this->owner->id]);
    $this->owner->forceFill(['current_team_id' => $this->team->id])->save();
    $this->assembly = bomItem($this->team, 'Assembly');
    $this->component = bomItem($this->team, 'Component');
    $this->foreignItem = bomItem(Team::factory()->create(), 'Foreign');
});

it('creates and edits a BOM in the app inventory panel without moving stock', function (): void {
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    expect(route('filament.app.resources.inventory-items.index'))->toEndWith('/app/inventory-items');

    Livewire::test(ListInventoryItems::class)
        ->assertCanSeeTableRecords([$this->assembly, $this->component])
        ->assertTableActionHidden('billOfMaterials', $this->foreignItem)
        ->callTableAction('billOfMaterials', $this->assembly, [
            'components' => [['component_item_id' => $this->component->id, 'quantity' => '2.125']],
        ])
        ->assertHasNoTableActionErrors()
        ->callTableAction('billOfMaterials', $this->assembly, [
            'components' => [['component_item_id' => $this->component->id, 'quantity' => '3']],
        ])
        ->assertHasNoTableActionErrors();

    expect($this->assembly->billOfMaterials()->sole()->quantity)->toBe('3.0000')
        ->and($this->assembly->refresh()->current_quantity)->toBe(0)
        ->and($this->assembly->transactions()->exists())->toBeFalse();
});

it('assigns new inventory items to the active team so they can have a BOM', function (): void {
    $team = Team::factory()->create(['user_id' => $this->owner->id]);
    $this->owner->forceFill(['current_team_id' => $team->id])->save();
    $account = Account::factory()->create(['team_id' => $team->id]);
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    Livewire::test(CreateInventoryItem::class)
        ->fillForm(['name' => 'New assembly', 'sku' => 'NEW-ASSEMBLY', 'unit_price' => 15, 'account_id' => $account->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect((int) InventoryItem::query()->where('sku', 'NEW-ASSEMBLY')->sole()->team_id)->toBe($team->id);
});

it('denies writes from a member without update permission or to an item outside the active team', function (): void {
    $member = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($member);
    $this->actingAs($member);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    Livewire::test(ListInventoryItems::class)
        ->assertTableActionHidden('billOfMaterials', $this->assembly);

    $save = app(SaveBillOfMaterials::class);
    expect(fn () => $save->handle($member, $this->assembly, []))->toThrow(AuthorizationException::class)
        ->and(fn () => $save->handle($this->owner, $this->foreignItem, []))->toThrow(AuthorizationException::class);
});

it('rejects invalid, duplicate, foreign and circular component selections without losing the saved BOM', function (): void {
    $save = app(SaveBillOfMaterials::class);
    $line = ['component_item_id' => $this->component->id, 'quantity' => '1.0001'];
    $save->handle($this->owner, $this->assembly, [$line]);

    foreach ([
        [$line, $line],
        [['component_item_id' => $this->foreignItem->id, 'quantity' => '1']],
        [['component_item_id' => $this->assembly->id, 'quantity' => '1']],
        [['component_item_id' => $this->component->id, 'quantity' => '0.00001']],
    ] as $components) {
        expect(fn () => $save->handle($this->owner, $this->assembly, $components))->toThrow(ValidationException::class);
    }

    expect(fn () => $save->handle($this->owner, $this->component, [
        ['component_item_id' => $this->assembly->id, 'quantity' => '1'],
    ]))->toThrow(ValidationException::class, 'A bill of materials cannot contain a circular reference.')
        ->and($this->assembly->billOfMaterials()->sole()->quantity)->toBe('1.0001');
});
