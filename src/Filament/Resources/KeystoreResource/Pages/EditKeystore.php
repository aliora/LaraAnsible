<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\KeystoreResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use VisioSoft\LaraAnsible\Filament\Resources\KeystoreResource;

class EditKeystore extends EditRecord
{
    protected static string $resource = KeystoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
