<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource;

class ViewInventory extends ViewRecord
{
    protected static string $resource = InventoryResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $hostsEntry = $this->getRecord()->hosts_entry;
        $data['hosts_entry'] = is_array($hostsEntry) ? $hostsEntry : [];

        return $data;
    }
}
