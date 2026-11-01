<?php

namespace App\Modules\Inventory\Filament\Resources\Suppliers\Pages;

use App\Modules\Inventory\Filament\Resources\Suppliers\SupplierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;
}
