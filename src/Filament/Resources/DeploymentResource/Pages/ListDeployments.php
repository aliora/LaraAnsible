<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\DeploymentResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use VisioSoft\LaraAnsible\Filament\Resources\DeploymentResource;

class ListDeployments extends ListRecords
{
    protected static string $resource = DeploymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
