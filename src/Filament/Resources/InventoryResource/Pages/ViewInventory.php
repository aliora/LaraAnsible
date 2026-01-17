<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource;

use Filament\Support\Enums\Width;

class ViewInventory extends ViewRecord
{
    protected static string $resource = InventoryResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }
}
