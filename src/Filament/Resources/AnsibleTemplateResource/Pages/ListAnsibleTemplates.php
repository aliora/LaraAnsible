<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\AnsibleTemplateResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use VisioSoft\LaraAnsible\Filament\Resources\AnsibleTemplateResource;

class ListAnsibleTemplates extends ListRecords
{
    protected static string $resource = AnsibleTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
