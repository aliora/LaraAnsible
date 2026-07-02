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
use VisioSoft\LaraAnsible\Filament\Concerns\AuthorizesAnsibleAccess;
use VisioSoft\LaraAnsible\Helpers\AnsibleImporter;
use VisioSoft\LaraAnsible\Helpers\TableHelper;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class Jobs extends Page implements HasTable
{
    use AuthorizesAnsibleAccess;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'ansible/jobs';

    protected string $view = 'laraansible::pages.jobs';

    public static function getNavigationGroup(): ?string
    {
        return __('laraansible::laraansible.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('laraansible::laraansible.job_templates');
    }

    public function getTitle(): string
    {
        return __('laraansible::laraansible.job_templates');
    }

    public function table(Table $table): Table
    {
        return TableHelper::configure($table)
            ->query(TaskTemplate::query()->orderBy('name'))
            ->heading(__('laraansible::laraansible.job_templates'))
            ->description(__('laraansible::laraansible.jobs_table_description'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('laraansible::laraansible.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('deployments_count')
                    ->counts('deployments')
                    ->label(__('laraansible::laraansible.runs'))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('laraansible::laraansible.active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('laraansible::laraansible.updated'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('import')
                    ->label(__('laraansible::laraansible.import'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->modalHeading(__('laraansible::laraansible.bulk_import_heading'))
                    ->modalDescription(__('laraansible::laraansible.jobs_bulk_import_description'))
                    ->schema([
                        Forms\Components\FileUpload::make('files')
                            ->label(__('laraansible::laraansible.ansible_files'))
                            ->multiple()
                            ->required()
                            ->preserveFilenames()
                            ->storeFiles(true)
                            ->disk('local')
                            ->directory('ansible-imports')
                            ->helperText(__('laraansible::laraansible.jobs_ansible_files_help')),
                    ])
                    ->action(function (array $data): void {
                        $counts = AnsibleImporter::import($data['files'] ?? []);

                        Notification::make()
                            ->success()
                            ->title(AnsibleImporter::summarize($counts))
                            ->send();
                    }),
                CreateAction::make()
                    ->label(__('laraansible::laraansible.add_job'))
                    ->modalHeading(__('laraansible::laraansible.add_job_template'))
                    ->schema($this->jobFormSchema()),
            ])
            ->actions(TableHelper::actionGroup([
                EditAction::make()
                    ->modalHeading(__('laraansible::laraansible.edit_job_template'))
                    ->schema($this->jobFormSchema()),
                DeleteAction::make(),
            ]))
            ->bulkActions(TableHelper::bulkActions())
            ->emptyStateHeading(__('laraansible::laraansible.no_job_templates'))
            ->emptyStateDescription(__('laraansible::laraansible.no_job_templates_description'))
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    protected function jobFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label(__('laraansible::laraansible.name'))
                ->required()
                ->maxLength(255)
                ->placeholder(__('laraansible::laraansible.template_name_placeholder'))
                ->columnSpanFull(),
            Forms\Components\Toggle::make('is_active')
                ->label(__('laraansible::laraansible.active'))
                ->default(true),
            Forms\Components\Textarea::make('playbook_content')
                ->label(__('laraansible::laraansible.playbook_yaml'))
                ->required()
                ->rows(18)
                ->columnSpanFull()
                ->placeholder("- hosts: all\n  become: true\n  tasks:\n    - name: Example\n      ansible.builtin.debug:\n        msg: hello {{ my_var | default('world') }}")
                ->helperText(__('laraansible::laraansible.jobs_playbook_help'))
                ->extraInputAttributes([
                    'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; line-height: 1.6; white-space: pre; overflow-x: auto;',
                    'spellcheck' => 'false',
                ]),
            Forms\Components\Repeater::make('input_vars')
                ->label(__('laraansible::laraansible.input_variables'))
                ->helperText(__('laraansible::laraansible.input_variables_help'))
                ->columnSpanFull()
                ->hintAction(
                    Action::make('detect_inputs')
                        ->label(__('laraansible::laraansible.detect_from_playbook'))
                        ->icon('heroicon-o-sparkles')
                        ->action(function ($get, $set): void {
                            $detected = AnsibleImporter::detectInputVars((string) $get('playbook_content'));

                            if (empty($detected)) {
                                Notification::make()
                                    ->warning()
                                    ->title(__('laraansible::laraansible.no_vars_prompt_found'))
                                    ->send();

                                return;
                            }

                            $set('input_vars', $detected);

                            Notification::make()
                                ->success()
                                ->title(__('laraansible::laraansible.inputs_detected', ['count' => count($detected)]))
                                ->body(__('laraansible::laraansible.inputs_detected_body'))
                                ->send();
                        })
                )
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('laraansible::laraansible.variable'))
                        ->required()
                        ->helperText(__('laraansible::laraansible.variable_help')),
                    Forms\Components\TextInput::make('label')
                        ->label(__('laraansible::laraansible.label'))
                        ->required(),
                    Forms\Components\TextInput::make('default')
                        ->label(__('laraansible::laraansible.default'))
                        ->helperText(__('laraansible::laraansible.default_help')),
                    Forms\Components\KeyValue::make('options')
                        ->label(__('laraansible::laraansible.choices'))
                        ->keyLabel(__('laraansible::laraansible.choices_key'))
                        ->valueLabel(__('laraansible::laraansible.choices_value'))
                        ->required()
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('required')
                        ->label(__('laraansible::laraansible.required'))
                        ->default(true),
                ])
                ->columns(2)
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['name'] ?? __('laraansible::laraansible.input'))
                ->addActionLabel(__('laraansible::laraansible.add_input'))
                ->default([]),
        ];
    }
}
