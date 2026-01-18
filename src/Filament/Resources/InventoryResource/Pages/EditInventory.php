<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource;
use VisioSoft\LaraAnsible\Models\Inventory;

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

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $hostsEntry = $this->getRecord()->hosts_entry;
        $data['hosts_entry'] = is_array($hostsEntry) ? $hostsEntry : [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $script = Inventory::buildInventoryScriptFromData($data);
        if ($script !== null) {
            $data['script'] = $script;
        }

        return $data;
    }
}
