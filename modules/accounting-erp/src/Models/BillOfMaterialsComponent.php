<?php

declare(strict_types=1);

namespace Liberu\Accounting\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BillOfMaterialsComponent extends Model
{
    protected $table = 'inventory_bom_components';

    protected $fillable = ['component_item_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:4'];

    public function componentItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'component_item_id');
    }
}
