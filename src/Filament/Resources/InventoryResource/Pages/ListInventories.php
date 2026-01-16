<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource;

class ListInventories extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
