<?php

declare(strict_types=1);

namespace Liberu\Accounting\Erp\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Accounting\Erp\Models\InventoryItem;

final class BillOfMaterialsPolicy
{
    public function activeTeamId(?Authenticatable $actor): ?int
    {
        if ($actor === null || ! method_exists($actor, 'belongsToTeam')) {
            return null;
        }

        $team = $actor->currentTeam;

        return $team && $actor->belongsToTeam($team)
            ? (int) $team->getKey()
            : null;
    }

    public function manageBillOfMaterials(?Authenticatable $actor, InventoryItem $item): bool
    {
        $teamId = $this->activeTeamId($actor);

        return $teamId !== null
            && $teamId === (int) $item->team_id
            && method_exists($actor, 'hasTeamPermission')
            && $actor->hasTeamPermission($actor->currentTeam, 'update');
    }
}
