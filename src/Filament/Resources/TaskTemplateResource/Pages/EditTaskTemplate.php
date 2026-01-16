<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\TaskTemplateResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use VisioSoft\LaraAnsible\Filament\Resources\TaskTemplateResource;

class EditTaskTemplate extends EditRecord
{
    protected static string $resource = TaskTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
