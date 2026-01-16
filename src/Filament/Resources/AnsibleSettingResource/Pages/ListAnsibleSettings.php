<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\AnsibleSettingResource\Pages;

use Filament\Resources\Pages\ListRecords;
use VisioSoft\LaraAnsible\Filament\Resources\AnsibleSettingResource;

class ListAnsibleSettings extends ListRecords
{
    protected static string $resource = AnsibleSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
