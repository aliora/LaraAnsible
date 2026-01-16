<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource;

class EditInventory extends EditRecord
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
