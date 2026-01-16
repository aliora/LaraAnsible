<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\KeystoreResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use VisioSoft\LaraAnsible\Filament\Resources\KeystoreResource;

class ListKeystores extends ListRecords
{
    protected static string $resource = KeystoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
