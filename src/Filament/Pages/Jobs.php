<?php

namespace VisioSoft\LaraAnsible\Filament\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class Jobs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Job Templates';

    protected static ?string $title = 'Job Templates';

    protected static ?string $slug = 'ansible/jobs';

    protected string $view = 'laraansible::pages.jobs';

    public function table(Table $table): Table
    {
        return $table
            ->query(TaskTemplate::query()->orderBy('name'))
            ->heading('Job Templates')
            ->description('Ansible playbooks. Each job is a complete, self-contained playbook — write all tasks inline (inline file contents with copy: content=... when needed).')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('deployments_count')
                    ->counts('deployments')
                    ->label('Runs')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('import')
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
                CreateAction::make()
                    ->label('Add Job')
                    ->modalHeading('Add Job Template')
                    ->schema($this->jobFormSchema()),
            ])
            ->actions([
                EditAction::make()
                    ->modalHeading('Edit Job Template')
                    ->schema($this->jobFormSchema()),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No job templates yet')
            ->emptyStateDescription('Add a playbook or import files.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    protected function jobFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255)
                ->placeholder('e.g. Install Gate')
                ->columnSpanFull(),
            Forms\Components\Toggle::make('is_active')
                ->label('Active')
                ->default(true),
            Forms\Components\Textarea::make('playbook_content')
                ->label('Playbook YAML')
                ->required()
                ->rows(18)
                ->columnSpanFull()
                ->placeholder("- hosts: all\n  become: true\n  tasks:\n    - name: Example\n      ansible.builtin.debug:\n        msg: hello {{ my_var | default('world') }}")
                ->helperText('Write a complete, self-contained playbook. Reference run-time inputs as {{ variable }} — values are passed in when the job is launched.')
                ->extraInputAttributes([
                    'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; line-height: 1.6; white-space: pre; overflow-x: auto;',
                    'spellcheck' => 'false',
                ]),
            Forms\Components\Repeater::make('input_vars')
                ->label('Input Variables')
                ->helperText('Asked when the job is launched, passed to the playbook as --extra-vars ({{ name }}).')
                ->columnSpanFull()
                ->hintAction(
                    Action::make('detect_inputs')
                        ->label('Detect from playbook')
                        ->icon('heroicon-o-sparkles')
                        ->action(function ($get, $set): void {
                            $detected = self::detectInputVars((string) $get('playbook_content'));

                            if (empty($detected)) {
                                Notification::make()
                                    ->warning()
                                    ->title('No vars_prompt found in the playbook')
                                    ->send();

                                return;
                            }

                            $set('input_vars', $detected);

                            Notification::make()
                                ->success()
                                ->title(count($detected).' input(s) detected')
                                ->body('Set the type and, for selects, the choices.')
                                ->send();
                        })
                )
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Variable')
                        ->required()
                        ->helperText('Referenced as {{ name }} in the playbook'),
                    Forms\Components\TextInput::make('label')
                        ->label('Label')
                        ->required(),
                    Forms\Components\TextInput::make('default')
                        ->label('Default')
                        ->helperText('Optional: one of the choice values, preselected'),
                    Forms\Components\KeyValue::make('options')
                        ->label('Choices (value → label)')
                        ->keyLabel('Value (sent to ansible)')
                        ->valueLabel('Label (shown to user)')
                        ->required()
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('required')
                        ->label('Required')
                        ->default(true),
                ])
                ->columns(2)
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['name'] ?? 'Input')
                ->addActionLabel('Add input')
                ->default([]),
        ];
    }

    /**
     * Parse Ansible `vars_prompt` blocks out of a playbook and map them to input
     * definitions (name/label/type/default). Choices aren't expressible in vars_prompt,
     * so selects still need their options set by hand afterwards.
     */
    protected static function detectInputVars(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parse($content);
        } catch (\Throwable $e) {
            return [];
        }

        if (! is_array($parsed)) {
            return [];
        }

        $out = [];
        foreach ($parsed as $play) {
            if (! is_array($play) || empty($play['vars_prompt']) || ! is_array($play['vars_prompt'])) {
                continue;
            }

            foreach ($play['vars_prompt'] as $vp) {
                if (! is_array($vp) || blank($vp['name'] ?? null)) {
                    continue;
                }

                $label = trim(strtok((string) ($vp['prompt'] ?? ''), "\n"));

                $out[] = [
                    'name' => $vp['name'],
                    'label' => $label !== '' ? $label : $vp['name'],
                    'default' => (string) ($vp['default'] ?? ''),
                    'options' => [],
                    'required' => true,
                ];
            }
        }

        return $out;
    }
}
