<?php

declare(strict_types=1);

namespace Liberu\Accounting\CustomerPortalFilament\Resources\Invoices\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Accounting\CustomerPortalFilament\Resources\Invoices\PortalInvoiceResource;

class ListPortalInvoices extends ListRecords
{
    #[\Override]
    protected static string $resource = PortalInvoiceResource::class;
}
