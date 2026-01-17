<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\AnsibleTemplateResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use VisioSoft\LaraAnsible\Filament\Resources\AnsibleTemplateResource;

class EditAnsibleTemplate extends EditRecord
{
    protected static string $resource = AnsibleTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
