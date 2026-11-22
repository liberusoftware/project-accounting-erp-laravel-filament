<?php

declare(strict_types=1);

namespace Liberu\Accounting\ErpFilament\Filament\App\Resources\InventoryItems;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\Erp\Actions\SaveBillOfMaterials;
use Liberu\Accounting\Erp\Models\InventoryItem;
use Liberu\Accounting\Erp\Modules\Inventory\InventoryModule;
use Liberu\Accounting\Erp\Policies\BillOfMaterialsPolicy;

final class BillOfMaterialsAction
{
    public static function make(): Action
    {
        return Action::make('billOfMaterials')
            ->label('Bill of materials')
            ->visible(InventoryModule::isActive())
            ->authorize(fn (InventoryItem $record): bool => app(BillOfMaterialsPolicy::class)
                ->manageBillOfMaterials(Filament::auth()->user(), $record))
            ->fillForm(fn (InventoryItem $record): array => [
                'components' => $record->billOfMaterials()->get(['component_item_id', 'quantity'])->toArray(),
            ])
            ->schema([
                Repeater::make('components')
                    ->minItems(1)
                    ->schema([
                        Select::make('component_item_id')
                            ->label('Component')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search, InventoryItem $record): array => self::components($record)
                                ->where(fn (Builder $query) => $query->whereLike('sku', "%{$search}%")->orWhereLike('name', "%{$search}%"))
                                ->orderBy('sku')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (InventoryItem $component): array => [$component->getKey() => self::componentLabel($component)])
                                ->all())
                            ->getOptionLabelUsing(function ($value, InventoryItem $record): ?string {
                                $component = self::components($record)->find($value);

                                return $component ? self::componentLabel($component) : null;
                            })
                            ->required(),
                        TextInput::make('quantity')->label('Quantity per unit')->numeric()->required(),
                    ])->columns(2),
            ])
            ->action(function (InventoryItem $record, array $data, SaveBillOfMaterials $save): void {
                $save->handle(Filament::auth()->user(), $record, $data['components']);
            })
            ->successNotificationTitle('Bill of materials saved');
    }

    private static function components(InventoryItem $record): Builder
    {
        $teamId = app(BillOfMaterialsPolicy::class)->activeTeamId(Filament::auth()->user());

        return InventoryItem::query()
            ->where('team_id', $teamId ?? -1)
            ->whereKeyNot($record->getKey());
    }

    private static function componentLabel(InventoryItem $component): string
    {
        return "{$component->sku} — {$component->name}";
    }
}
