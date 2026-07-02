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
                ->label(__('laraansible::laraansible.import_playbooks'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading(__('laraansible::laraansible.bulk_import_jobs_heading'))
                ->modalDescription(__('laraansible::laraansible.bulk_import_jobs_description'))
                ->schema([
                    Forms\Components\FileUpload::make('files')
                        ->label(__('laraansible::laraansible.playbook_files'))
                        ->multiple()
                        ->required()
                        ->preserveFilenames()
                        ->storeFiles(true)
                        ->disk('local')
                        ->directory('ansible-playbook-imports')
                        ->helperText(__('laraansible::laraansible.playbook_files_help')),
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
                        ->title(__('laraansible::laraansible.imported_job_templates', ['count' => $count]))
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
