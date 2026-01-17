<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource;

use Filament\Support\Enums\Width;

class CreateInventory extends CreateRecord
{
    protected static string $resource = InventoryResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }
}
