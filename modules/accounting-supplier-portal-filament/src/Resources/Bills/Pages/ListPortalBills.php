<?php

declare(strict_types=1);

namespace Liberu\Accounting\SupplierPortalFilament\Resources\Bills\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Accounting\SupplierPortalFilament\Resources\Bills\PortalBillResource;

class ListPortalBills extends ListRecords
{
    #[\Override]
    protected static string $resource = PortalBillResource::class;
}
