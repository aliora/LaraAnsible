<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\DeploymentResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use VisioSoft\LaraAnsible\Filament\Resources\DeploymentResource;

class EditDeployment extends EditRecord
{
    protected static string $resource = DeploymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getFormActions(): array
    {
        // Remove Save/Cancel actions on Edit page - read-only & actions handled separately
        return [];
    }
}
