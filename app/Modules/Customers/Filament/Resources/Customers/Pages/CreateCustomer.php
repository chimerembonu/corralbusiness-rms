<?php

namespace App\Modules\Customers\Filament\Resources\Customers\Pages;

use App\Modules\Customers\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;
}
