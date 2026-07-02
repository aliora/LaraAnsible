<?php

namespace VisioSoft\LaraAnsible\Filament\Pages;

use Filament\Actions\Action;
use Filament\Actions\Action as SchemaAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use VisioSoft\LaraAnsible\Filament\Concerns\AuthorizesAnsibleAccess;
use VisioSoft\LaraAnsible\Helpers\TableHelper;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\Keystore;

class ManageAnsibleSettings extends Page implements HasForms, HasTable
{
    use AuthorizesAnsibleAccess;
    use InteractsWithFormActions;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 99;

    protected static ?string $slug = 'ansible/settings';

    protected string $view = 'laraansible::pages.manage-ansible-settings';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('laraansible::laraansible.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('laraansible::laraansible.ansible_settings');
    }

    public function getTitle(): string
    {
        return __('laraansible::laraansible.ansible_settings');
    }

    public function mount(): void
    {
        $this->form->fill(AnsibleSetting::getInstance()->toArray());
    }

    public function form(Schema $schema): Schema
    {
        $setting = AnsibleSetting::getInstance();
        $isConfigured = ! empty($setting->parent_table) && ! empty($setting->child_table);

        if ($isConfigured) {
            return $schema
                ->schema([
                    Section::make(__('laraansible::laraansible.current_configuration'))
                        ->description(__('laraansible::laraansible.integration_active'))
                        ->icon('heroicon-o-check-circle')
                        ->iconColor('success')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    Forms\Components\TextInput::make('parent_table')
                                        ->label(__('laraansible::laraansible.group_source'))
                                        ->prefixIcon('heroicon-o-folder')
                                        ->disabled(),
                                    Forms\Components\TextInput::make('child_table')
                                        ->label(__('laraansible::laraansible.device_source'))
                                        ->prefixIcon('heroicon-o-server-stack')
                                        ->disabled(),
                                    Forms\Components\TextInput::make('ssh_username')
                                        ->label(__('laraansible::laraansible.ssh_user'))
                                        ->prefixIcon('heroicon-o-user')
                                        ->disabled(),
                                    Forms\Components\TextInput::make('child_hostname_column')
                                        ->label(__('laraansible::laraansible.ip_hostname_column'))
                                        ->prefixIcon('heroicon-o-globe-alt')
                                        ->disabled(),
                                    Forms\Components\TextInput::make('version_column')
                                        ->label(__('laraansible::laraansible.version_column'))
                                        ->prefixIcon('heroicon-o-tag')
                                        ->placeholder(__('laraansible::laraansible.not_configured'))
                                        ->disabled(),
                                ]),

                            Actions::make([
                                SchemaAction::make('reset_settings')
                                    ->label(__('laraansible::laraansible.reset_reconfigure'))
                                    ->icon('heroicon-o-arrow-path')
                                    ->color('danger')
                                    ->requiresConfirmation()
                                    ->modalHeading(__('laraansible::laraansible.reset_settings_heading'))
                                    ->modalDescription(__('laraansible::laraansible.reset_settings_description'))
                                    ->action(function () use ($setting) {
                                        $setting->update([
                                            'parent_table' => null,
                                            'child_table' => null,
                                        ]);
                                        redirect(request()->header('Referer'));
                                    }),
                            ]),
                        ]),
                ])
                ->statePath('data');
        }

        return $schema
            ->schema([
                Wizard::make([
                    Wizard\Step::make('parent')
                        ->label(__('laraansible::laraansible.step_group_source'))
                        ->description(__('laraansible::laraansible.step_group_source_description'))
                        ->icon('heroicon-o-folder')
                        ->schema([
                            Section::make(__('laraansible::laraansible.main_table_heading'))
                                ->description(__('laraansible::laraansible.main_table_description'))
                                ->schema([
                                    Forms\Components\Select::make('parent_table')
                                        ->label(__('laraansible::laraansible.table_name'))
                                        ->options(fn () => AnsibleSetting::getAvailableTables())
                                        ->searchable()
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set) => $set('parent_label_column', null))
                                        ->helperText(__('laraansible::laraansible.parent_table_help')),

                                    Forms\Components\Select::make('parent_label_column')
                                        ->label(__('laraansible::laraansible.display_column'))
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('parent_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText(__('laraansible::laraansible.display_column_help')),
                                ])
                                ->columns(2),
                        ]),

                    Wizard\Step::make('child')
                        ->label(__('laraansible::laraansible.step_device_source'))
                        ->description(__('laraansible::laraansible.step_device_source_description'))
                        ->icon('heroicon-o-server-stack')
                        ->schema([
                            Section::make(__('laraansible::laraansible.device_table_heading'))
                                ->description(__('laraansible::laraansible.device_table_description'))
                                ->schema([
                                    Forms\Components\Select::make('child_table')
                                        ->label(__('laraansible::laraansible.table_name'))
                                        ->options(fn () => AnsibleSetting::getAvailableTables())
                                        ->searchable()
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set) {
                                            $set('child_label_column', null);
                                            $set('child_parent_foreign_key', null);
                                            $set('version_column', null);
                                            $set('child_hostname_column', null);
                                            $set('child_port_column', null);
                                            $set('child_username_column', null);
                                        })
                                        ->helperText(__('laraansible::laraansible.child_table_help')),

                                    Forms\Components\Select::make('child_parent_foreign_key')
                                        ->label(__('laraansible::laraansible.group_foreign_key'))
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText(__('laraansible::laraansible.group_foreign_key_help')),
                                ])
                                ->columns(2),

                            Fieldset::make(__('laraansible::laraansible.device_details'))
                                ->schema([
                                    Forms\Components\Select::make('child_label_column')
                                        ->label(__('laraansible::laraansible.device_name_column'))
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText(__('laraansible::laraansible.device_name_column_help')),

                                    Forms\Components\Select::make('version_column')
                                        ->label(__('laraansible::laraansible.version_column'))
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->placeholder(__('laraansible::laraansible.optional'))
                                        ->helperText(__('laraansible::laraansible.version_column_help')),
                                ])
                                ->columns(2),
                        ]),

                    Wizard\Step::make('connection')
                        ->label(__('laraansible::laraansible.step_connection'))
                        ->description(__('laraansible::laraansible.step_connection_description'))
                        ->icon('heroicon-o-link')
                        ->schema([
                            Section::make(__('laraansible::laraansible.ssh_connection_settings'))
                                ->description(__('laraansible::laraansible.ssh_connection_settings_description'))
                                ->schema([
                                    Forms\Components\Select::make('child_hostname_column')
                                        ->label(__('laraansible::laraansible.ip_hostname_column'))
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText(__('laraansible::laraansible.ip_hostname_column_help')),

                                    Forms\Components\TextInput::make('ssh_port')
                                        ->label(__('laraansible::laraansible.ssh_port'))
                                        ->default('22')
                                        ->placeholder('22')
                                        ->helperText(__('laraansible::laraansible.ssh_port_help')),

                                    Forms\Components\TextInput::make('ssh_username')
                                        ->label(__('laraansible::laraansible.ssh_username'))
                                        ->default('root')
                                        ->placeholder('root')
                                        ->helperText(__('laraansible::laraansible.ssh_username_help')),
                                ])
                                ->columns(3),
                        ]),
                ])
                    ->submitAction(null),
            ])
            ->statePath('data');
    }

    public function table(Table $table): Table
    {
        return TableHelper::configure($table)
            ->query(Keystore::query()->orderBy('name'))
            ->heading(__('laraansible::laraansible.ssh_keys'))
            ->description(__('laraansible::laraansible.ssh_keys_description'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('laraansible::laraansible.key_name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('private_key')
                    ->label(__('laraansible::laraansible.key'))
                    ->formatStateUsing(fn (?string $state): string => Str::limit(preg_replace('/\s+/', ' ', (string) $state), 50) ?: '—')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laraansible::laraansible.added'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('laraansible::laraansible.add_ssh_key'))
                    ->modalHeading(__('laraansible::laraansible.add_ssh_key'))
                    ->schema($this->keystoreFormSchema()),
            ])
            ->actions(TableHelper::actionGroup([
                EditAction::make()
                    ->modalHeading(__('laraansible::laraansible.edit_ssh_key'))
                    ->schema($this->keystoreFormSchema()),
                DeleteAction::make()
                    ->before(function (Keystore $record, DeleteAction $action) {
                        if ($record->inventories()->exists()) {
                            Notification::make()
                                ->warning()
                                ->title(__('laraansible::laraansible.key_in_use'))
                                ->body(__('laraansible::laraansible.key_in_use_body'))
                                ->send();

                            $action->cancel();
                        }
                    }),
            ]))
            ->bulkActions(TableHelper::bulkActions())
            ->emptyStateHeading(__('laraansible::laraansible.no_ssh_keys'))
            ->emptyStateDescription(__('laraansible::laraansible.no_ssh_keys_description'))
            ->emptyStateIcon('heroicon-o-key');
    }

    protected function keystoreFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label(__('laraansible::laraansible.key_name'))
                ->required()
                ->placeholder('e.g. gate-prod'),
            Forms\Components\Textarea::make('private_key')
                ->label(__('laraansible::laraansible.private_key_pem'))
                ->required()
                ->rows(6)
                ->placeholder('-----BEGIN OPENSSH PRIVATE KEY-----'),
        ];
    }

    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('laraansible::laraansible.save_settings'))
                ->icon('heroicon-o-check')
                ->color('success')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        AnsibleSetting::getInstance()->update($this->form->getState());

        Notification::make()
            ->title(__('laraansible::laraansible.settings_saved'))
            ->body(__('laraansible::laraansible.settings_saved_body'))
            ->success()
            ->send();
    }
}
