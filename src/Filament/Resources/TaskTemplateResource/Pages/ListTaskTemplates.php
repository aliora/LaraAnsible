<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\TaskTemplateResource\Pages;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use VisioSoft\LaraAnsible\Filament\Resources\TaskTemplateResource;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class ListTaskTemplates extends ListRecords
{
    protected static string $resource = TaskTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')
                ->label('Import Playbooks')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Bulk Import Job Templates')
                ->modalDescription('Upload one or more playbook files. Each file becomes a job template (filename = name).')
                ->schema([
                    Forms\Components\FileUpload::make('files')
                        ->label('Playbook Files')
                        ->multiple()
                        ->required()
                        ->preserveFilenames()
                        ->storeFiles(true)
                        ->disk('local')
                        ->directory('ansible-playbook-imports')
                        ->helperText('e.g. install-all.yml, git-pull.yml'),
                ])
                ->action(function (array $data): void {
                    $count = 0;

                    foreach ($data['files'] ?? [] as $path) {
                        if (! Storage::disk('local')->exists($path)) {
                            continue;
                        }

                        TaskTemplate::updateOrCreate(
                            ['name' => pathinfo(basename($path), PATHINFO_FILENAME)],
                            ['playbook_content' => Storage::disk('local')->get($path), 'is_active' => true],
                        );

                        Storage::disk('local')->delete($path);
                        $count++;
                    }

                    Notification::make()
                        ->success()
                        ->title("Imported {$count} job template(s)")
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
