<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\AnsibleSettingResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use VisioSoft\LaraAnsible\Filament\Resources\AnsibleSettingResource;

class EditAnsibleSetting extends EditRecord
{
    protected static string $resource = AnsibleSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
