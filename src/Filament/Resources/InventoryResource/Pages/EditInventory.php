<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource;

use Filament\Support\Enums\Width;

class EditInventory extends EditRecord
{
    protected static string $resource = InventoryResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
