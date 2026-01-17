<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\TaskTemplateResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use VisioSoft\LaraAnsible\Filament\Resources\TaskTemplateResource;

class CreateTaskTemplate extends CreateRecord
{
    protected static string $resource = TaskTemplateResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }
}
