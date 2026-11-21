<?php

declare(strict_types=1);

namespace Liberu\Accounting\ErpFilament\Filament\App\Resources\InventoryItems\Pages;

use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\InventoryItem;
use Liberu\Accounting\Erp\Policies\BillOfMaterialsPolicy;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\InventoryItems\InventoryItemResource;

class CreateInventoryItem extends CreateRecord
{
    #[\Override]
    protected static string $resource = InventoryItemResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $teamId = app(BillOfMaterialsPolicy::class)->activeTeamId(Filament::auth()->user());
        abort_if($teamId === null, 403);

        if (! Account::query()->where('team_id', $teamId)->whereKey($data['account_id'])->exists()) {
            throw ValidationException::withMessages(['data.account_id' => 'Select an inventory account from the current team.']);
        }

        $item = new InventoryItem($data);
        $item->team_id = $teamId;
        $item->save();

        return $item;
    }
}
