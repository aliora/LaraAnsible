<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource;
use VisioSoft\LaraAnsible\Models\Inventory;

class CreateInventory extends CreateRecord
{
    protected static string $resource = InventoryResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $script = Inventory::buildInventoryScriptFromData($data);
        if ($script !== null) {
            $data['script'] = $script;
        }

        return $data;
    }
}
