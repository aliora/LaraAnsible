<?php

namespace VisioSoft\LaraAnsible\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Actions;
use Filament\Actions\Action as SchemaAction;
use Filament\Schemas\Schema;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;

class ManageAnsibleSettings extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationLabel = 'Ansible Settings';

    protected static ?string $title = 'Ansible Settings';

    protected static ?string $slug = 'ansible/settings';

    protected string $view = 'laraansible::pages.manage-ansible-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $setting = AnsibleSetting::getInstance();

        $this->form->fill($setting->toArray());
    }

    public function form(Schema $schema): Schema
    {
        $setting = AnsibleSetting::getInstance();
        $isConfigured = ! empty($setting->parent_table) && ! empty($setting->child_table);

        if ($isConfigured) {
            return $schema
                ->schema([
                    Section::make('Current Configuration')
                        ->description('Your Ansible integration is active.')
                        ->icon('heroicon-o-check-circle')
                        ->iconColor('success')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    Forms\Components\TextInput::make('parent_table')
                                        ->label('Group Source')
                                        ->prefixIcon('heroicon-o-folder')
                                        ->disabled(),
                                    Forms\Components\TextInput::make('child_table')
                                        ->label('Device Source')
                                        ->prefixIcon('heroicon-o-server-stack')
                                        ->disabled(),
                                    Forms\Components\TextInput::make('ssh_username')
                                        ->label('SSH User')
                                        ->prefixIcon('heroicon-o-user')
                                        ->disabled(),
                                    Forms\Components\TextInput::make('child_hostname_column')
                                        ->label('IP/Hostname Column')
                                        ->prefixIcon('heroicon-o-globe-alt')
                                        ->disabled(),
                                    Forms\Components\TextInput::make('version_column')
                                        ->label('Version Column')
                                        ->prefixIcon('heroicon-o-tag')
                                        ->placeholder('Not Configured')
                                        ->disabled(),
                                ]),

                            Grid::make(3)
                                ->schema([
                                    Forms\Components\TextInput::make('ssh_port')
                                        ->label('SSH Port')
                                        ->numeric(),
                                    Forms\Components\TextInput::make('ssh_username')
                                        ->label('SSH Username'),
                                    
                                     Actions::make([
                                        SchemaAction::make('save_changes')
                                            ->label('Update SSH Settings')
                                            ->icon('heroicon-o-check')
                                            ->color('primary')
                                            ->action(function (Get $get) use ($setting) {
                                                $setting->update([
                                                    'ssh_port' => $get('ssh_port'),
                                                    'ssh_username' => $get('ssh_username'),
                                                ]);
                                                Notification::make()
                                                    ->title('SSH Settings Updated')
                                                    ->success()
                                                    ->send();
                                            }),
                                    ])->alignEnd(),
                                ]),
                                
                            Actions::make([
                                SchemaAction::make('reset_settings')
                                    ->label('Reset & Reconfigure')
                                    ->icon('heroicon-o-arrow-path')
                                    ->color('danger')
                                    ->requiresConfirmation()
                                    ->modalHeading('Reset Ansible Settings?')
                                    ->modalDescription('This will clear your current table mappings. You will need to run the setup wizard again.')
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
                        ->label('1. Group Source')
                        ->description('Select the main grouping table')
                        ->icon('heroicon-o-folder')
                        ->schema([
                            Section::make('Main Table (Parent/Group)')
                                ->description('The main table where devices are grouped. E.g., parks, locations, branches')
                                ->schema([
                                    Forms\Components\Select::make('parent_table')
                                        ->label('Table Name')
                                        ->options(fn () => AnsibleSetting::getAvailableTables())
                                        ->searchable()
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set) => $set('parent_label_column', null))
                                        ->helperText('Select the group/park table in your database'),

                                    Forms\Components\Select::make('parent_label_column')
                                        ->label('Display Column')
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('parent_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText('Column to display in list (e.g. name, title)'),
                                ])
                                ->columns(2),
                        ]),

                    Wizard\Step::make('child')
                        ->label('2. Device Source')
                        ->description('Select the devices to be managed')
                        ->icon('heroicon-o-server-stack')
                        ->schema([
                            Section::make('Device Table (Child/Devices)')
                                ->description('The table containing devices managed by Ansible. E.g., devices, kiosks, servers')
                                ->schema([
                                    Forms\Components\Select::make('child_table')
                                        ->label('Table Name')
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
                                        ->helperText('Select the table where your devices are located'),

                                    Forms\Components\Select::make('child_parent_foreign_key')
                                        ->label('Group Relationship Column (Foreign Key)')
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText('Column linking to the parent table (e.g. park_id)'),
                                ])
                                ->columns(2),

                            Fieldset::make('Device Details')
                                ->schema([
                                    Forms\Components\Select::make('child_label_column')
                                        ->label('Device Name Column')
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText('Display name of the device'),

                                    Forms\Components\Select::make('version_column')
                                        ->label('Version Column')
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->placeholder('Optional')
                                        ->helperText('If software version is tracked'),
                                ])
                                ->columns(2),
                        ]),

                    Wizard\Step::make('connection')
                        ->label('3. Connection Settings')
                        ->description('Map SSH connection details')
                        ->icon('heroicon-o-link')
                        ->schema([
                            Section::make('SSH Connection Settings')
                                ->description('Information used by Ansible to connect to devices')
                                ->schema([
                                    Forms\Components\Select::make('child_hostname_column')
                                        ->label('IP/Hostname Column')
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText('Column containing device IP address or hostname'),

                                    Forms\Components\TextInput::make('ssh_port')
                                        ->label('SSH Port')
                                        ->default('22')
                                        ->placeholder('22')
                                        ->helperText('Default SSH port for all devices'),

                                    Forms\Components\TextInput::make('ssh_username')
                                        ->label('SSH Username')
                                        ->default('root')
                                        ->placeholder('root')
                                        ->helperText('Default SSH username for all devices'),
                                ])
                                ->columns(3),
                        ]),
                ])
                    ->submitAction(null),
            ])
            ->statePath('data');
    }

    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Settings')
                ->icon('heroicon-o-check')
                ->color('success')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $setting = AnsibleSetting::getInstance();
        $setting->update($data);

        Notification::make()
            ->title('Settings Saved')
            ->body('Ansible integration settings updated successfully.')
            ->success()
            ->send();
    }
}
