<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\TaskTemplateResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use VisioSoft\LaraAnsible\Filament\Resources\TaskTemplateResource;

class ListTaskTemplates extends ListRecords
{
    protected static string $resource = TaskTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
