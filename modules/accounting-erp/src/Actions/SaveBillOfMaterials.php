<?php

declare(strict_types=1);

namespace Liberu\Accounting\Erp\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\Erp\Models\BillOfMaterialsComponent;
use Liberu\Accounting\Erp\Models\InventoryItem;
use Liberu\Accounting\Erp\Policies\BillOfMaterialsPolicy;
use Liberu\Foundation\ModuleManager\ModuleRegistry;

final readonly class SaveBillOfMaterials
{
    public function __construct(private BillOfMaterialsPolicy $policy) {}

    public function handle(Authenticatable $actor, InventoryItem $item, array $components): void
    {
        if (! app(ModuleRegistry::class)->enabled('accounting-inventory-accounting', (array) config('modules.enabled', []), (array) config('modules.disabled', [])) || ! $this->policy->manageBillOfMaterials($actor, $item)) {
            throw new AuthorizationException();
        }

        $validated = Validator::make(['components' => $components], [
            'components' => ['required', 'array', 'list', 'min:1'],
            'components.*' => ['required', 'array:component_item_id,quantity'],
            'components.*.component_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'components.*.quantity' => ['required', 'numeric', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'min:0.0001'],
        ])->validate()['components'];

        DB::transaction(function () use ($item, $validated): void {
            // Serialise edits for this team's graph so concurrent edits cannot create a cycle.
            $teamItems = InventoryItem::query()->where('team_id', $item->team_id)
                ->orderBy('id')->lockForUpdate()->pluck('id');
            $itemId = (int) $item->getKey();

            if (! $teamItems->contains($itemId)) {
                throw new AuthorizationException();
            }

            $componentIds = array_map('intval', array_column($validated, 'component_item_id'));
            if (array_diff($componentIds, $teamItems->all()) !== []) {
                throw ValidationException::withMessages(['components' => 'Select components from the current team.']);
            }

            $edges = BillOfMaterialsComponent::query()->whereIn('item_id', $teamItems)
                ->where('item_id', '!=', $itemId)->get(['item_id', 'component_item_id'])
                ->groupBy('item_id');
            $pending = $componentIds;
            $visited = [];

            while ($pending !== []) {
                $componentId = array_pop($pending);
                if ($componentId === $itemId) {
                    throw ValidationException::withMessages(['components' => 'A bill of materials cannot contain a circular reference.']);
                }
                if (isset($visited[$componentId])) {
                    continue;
                }

                $visited[$componentId] = true;
                foreach ($edges->get($componentId, []) as $edge) {
                    $pending[] = (int) $edge->component_item_id;
                }
            }

            $item->billOfMaterials()->delete();
            $item->billOfMaterials()->createMany($validated);
        }, attempts: 3);
    }
}
